# Debug: Chatbot Knowledge Base ignore kar raha hai

## Sab se pehle: ek command me root cause

```bash
php artisan ai:diagnose
```

Naya **"Knowledge base / RAG"** section exactly batata hai ke masla kahan hai:

| Output | Matlab | Fix |
|---|---|---|
| `attached: NO` | Bot se KB lagi hi nahi | Bot edit kar ke KB attach karo |
| `chunks: 0` | Document index nahi hua (queue worker band hai) | `queue:restart` + worker check, phir document re-save |
| `with embedding: 0` + `via keyword` + `0 found` | Embeddings kabhi bani hi nahi (embed-capable provider missing tha) aur keyword fallback miss ho gaya | OpenAI/Gemini provider add karo, phir document re-index |
| `retrieval probe: N found / N injected` | KB pipeline theek hai — masla model/upstream hai | Neeche workspace/probes section dekho (dead upstream) |

Admin UI me bhi: **Admin → Settings → OmniRoute → 🔍 Run Full Diagnostic** — ab report me KB section bhi aata hai.

## Agar diagnostic sab green de lekin bot phir bhi KB ignore kare

1. **Playground me dobara test karo** aur reply ka `meta` dekho — `meta.model` mein kaunsa upstream model aaya?
2. Agar model pinned concrete id hai (jaise `gpt-4o-mini`) → workspace-level provider config check karo:
   ```bash
   php artisan tinker --execute="dump(\App\Modules\AI\Models\AiProviderConfig::all(['id','workspace_id','provider','enabled','default_model_chat'])->toArray());"
   ```
   Workspace apna provider pehle use karta hai — agar wahan dead model laga hai to system probes green hone ke bawajood bot garbage dega. Fix: workspace provider ka chat model `auto/chat` karo ya workspace provider disable karo.
3. Bad reply ke baad turant queue/log dekho:
   ```bash
   tail -50 storage/logs/laravel.json | grep -E "dead_upstream|llm.chat"
   ```

## Query aisi karo jiska answer KB me ho

Diagnostic ka probe canary question use karta hai. Playground me aisa question poocho jo **literally KB text me maujood ho** (jaise SpaGreen KB me "what treatments do you offer" → "massage, facial and body treatments" chunk me hai). Generic sawal ("what is your company name") jab KB me company ka naam hi na ho to generic reply expected hai.

## FAQ/JSON KB: re-index zaroori hai (chunking fix ke baad)

Purana bug: FAQ/JSON documents ek hi 800-word chunk me index hote the — question ek chunk me, answer agle me. Retrieval galat chunk uthati thi aur bot "confirm with team" bolta tha, halanke KB me jawab mojood tha.

Fix deploy ke baad **har FAQ/JSON document ko re-index karo** (KB UI me document re-save / Re-index button), phir verify karo:

```bash
# Chunks ab chhote Q&A-pair sized hone chahiye:
php artisan tinker --execute="\App\Modules\AI\Models\AiKbChunk::where('kb_id',1)->orderBy('id')->get(['id','ord','content'])->each(fn($c)=>print($c->id.' | '.mb_substr(preg_replace('/\s+/',' ',$c->content),0,80).PHP_EOL));"
# Diagnostic ka retrieval probe ab correct chunk dikhaye:
php artisan ai:diagnose
```

## Embeddings dobara banane ka tareeqa

Embeddings missing hain to document ko KB UI me dobara save karo — `IndexDocumentJob` dobara chalega aur ab embed provider milne par vectors ban jayenge:

```bash
# worker chal raha hai?
php artisan queue:restart
# phir KB UI me document re-save karo, ya:
php artisan tinker --execute="\App\Modules\AI\Jobs\IndexDocumentJob::dispatch(1)->onQueue('ai');"
# phir check karo:
php artisan tinker --execute="echo \App\Modules\AI\Models\AiKbChunk::where('kb_id',1)->whereNotNull('embedding')->count() . ' / ' . \App\Modules\AI\Models\AiKbChunk::where('kb_id',1)->count();"
```

Note: `ai:diagnose` ka retrieval probe live LLM embed call karta hai — agar embed provider configured hai to yeh bhi use karega, warna keyword fallback (bilkul waise hi jaise ChatbotRunner karta hai).
