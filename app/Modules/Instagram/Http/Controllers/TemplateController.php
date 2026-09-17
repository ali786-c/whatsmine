<?php

namespace App\Modules\Instagram\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Instagram\Models\InstagramTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Saved Instagram message templates (generic carousel / button) — the Inbox
 * composer lists these, saves new ones, and deletes them. No Meta approval is
 * involved (unlike WhatsApp templates): these are just stored compositions of
 * the Graph generic/button template payload.
 */
class TemplateController extends Controller
{
    /** Full-page template gallery (Instagram menu → Templates) */
    public function gallery(Request $request): \Inertia\Response
    {
        return Inertia::render('Instagram/Templates/Index', [
            'templates' => $this->allForWorkspace($request),
        ]);
    }

    /** Full-page editor — new template (with live preview) */
    public function create(): \Inertia\Response
    {
        return Inertia::render('Instagram/Templates/Edit', [
            'template' => null,
        ]);
    }

    /** Full-page editor — existing template (with live preview) */
    public function edit(Request $request, InstagramTemplate $template): \Inertia\Response
    {
        abort_unless((int) $template->workspace_id === $this->workspaceId($request), 403);

        return Inertia::render('Instagram/Templates/Edit', [
            'template' => $template->only(['id', 'name', 'type', 'definition']),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            InstagramTemplate::forWorkspace($this->workspaceId($request))
                ->orderByDesc('created_at')
                ->get(['id', 'name', 'type', 'definition'])
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $template = InstagramTemplate::create($data + [
            'workspace_id' => $this->workspaceId($request),
            'created_by' => $request->user()->id,
        ]);

        return response()->json($template->only(['id', 'name', 'type', 'definition']), 201);
    }

    /** Saved-templates list used by both the gallery and the Inbox composer */
    private function allForWorkspace(Request $request): array
    {
        return InstagramTemplate::forWorkspace($this->workspaceId($request))
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'type', 'definition'])
            ->all();
    }

    public function update(Request $request, InstagramTemplate $template): JsonResponse
    {
        abort_unless((int) $template->workspace_id === $this->workspaceId($request), 403);

        $template->update($this->validated($request));

        return response()->json($template->only(['id', 'name', 'type', 'definition']));
    }

    public function destroy(Request $request, InstagramTemplate $template): Response
    {
        abort_unless((int) $template->workspace_id === $this->workspaceId($request), 403);

        $template->delete();

        return response()->noContent();
    }

    /**
     * Meta hard limits (Generic/Button Template docs): 10 elements max,
     * title/subtitle 80 chars, 3 buttons per element, button-template text 640
     * bytes, button titles 20 chars, payloads 1000 chars.
     *
     * @return array{name: string, type: string, definition: array<string, mixed>}
     */
    private function validated(Request $request): array
    {
        // NOTE: Laravel's ConvertEmptyStringsToNull middleware turns '' into null
        // BEFORE validation, so every nested field must be nullable here — the
        // active type's required fields are enforced explicitly below. Required
        // nested rules would otherwise reject a button save carrying an untouched
        // empty elements array with a confusing "must be a string" error.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:generic,button'],
            'definition' => ['required', 'array'],
            // Button template
            'definition.text' => ['nullable', 'string', 'max:640'],
            'definition.buttons' => ['nullable', 'array', 'max:3'],
            'definition.buttons.*.type' => ['nullable', 'in:web_url,postback'],
            'definition.buttons.*.title' => ['nullable', 'string', 'max:20'],
            'definition.buttons.*.url' => ['nullable', 'url', 'max:2048'],
            'definition.buttons.*.payload' => ['nullable', 'string', 'max:1000'],
            // Generic carousel
            'definition.elements' => ['nullable', 'array', 'max:10'],
            'definition.elements.*.title' => ['nullable', 'string', 'max:80'],
            'definition.elements.*.subtitle' => ['nullable', 'string', 'max:80'],
            'definition.elements.*.image_url' => ['nullable', 'url', 'max:2048'],
            'definition.elements.*.buttons' => ['nullable', 'array', 'max:3'],
            'definition.elements.*.buttons.*.type' => ['nullable', 'in:web_url,postback'],
            'definition.elements.*.buttons.*.title' => ['nullable', 'string', 'max:20'],
            'definition.elements.*.buttons.*.url' => ['nullable', 'url', 'max:2048'],
            'definition.elements.*.buttons.*.payload' => ['nullable', 'string', 'max:1000'],
        ]);

        // Normalise buttons: complete rows only (title + url/payload), typed for Graph.
        $normaliseButtons = fn (array $buttons): array => collect($buttons)
            ->map(function (array $b): array {
                $type = (($b['type'] ?? null) === 'postback') ? 'postback' : 'web_url';
                $title = trim((string) ($b['title'] ?? ''));
                $target = trim((string) ($b[$type === 'postback' ? 'payload' : 'url'] ?? ''));

                if ($title === '' || $target === '') {
                    return []; // incomplete row — dropped
                }

                return $type === 'postback'
                    ? ['type' => 'postback', 'title' => $title, 'payload' => $target]
                    : ['type' => 'web_url', 'title' => $title, 'url' => $target];
            })
            ->filter(fn (array $b) => $b !== [])
            ->values()
            ->all();

        $definition = $data['definition'];

        if ($data['type'] === 'button') {
            $text = trim((string) ($definition['text'] ?? ''));
            $buttons = $normaliseButtons((array) ($definition['buttons'] ?? []));

            if ($text === '' || $buttons === []) {
                throw ValidationException::withMessages([
                    'definition' => 'Button templates need message text and at least one complete button (title + URL/payload).',
                ]);
            }

            return [
                'name' => $data['name'],
                'type' => 'button',
                'definition' => ['text' => $text, 'buttons' => $buttons],
            ];
        }

        $elements = collect((array) ($definition['elements'] ?? []))
            ->map(function (array $el) use ($normaliseButtons): array {
                $out = ['title' => trim((string) ($el['title'] ?? ''))];

                if (! empty($el['subtitle'])) {
                    $out['subtitle'] = $el['subtitle'];
                }

                if (! empty($el['image_url'])) {
                    $out['image_url'] = $el['image_url'];
                }

                if (! empty($el['buttons'])) {
                    $out['buttons'] = $normaliseButtons($el['buttons']);
                }

                return $out;
            })
            ->filter(fn (array $el) => $el['title'] !== '')
            ->values()
            ->all();

        if ($elements === []) {
            throw ValidationException::withMessages([
                'definition' => 'Each carousel card needs a title.',
            ]);
        }

        return [
            'name' => $data['name'],
            'type' => 'generic',
            'definition' => ['elements' => $elements],
        ];
    }

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }
}
