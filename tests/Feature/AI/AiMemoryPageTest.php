<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * HTTP smoke tests for the AI Memory management page and its CRUD endpoints —
 * the full client-app middleware stack (auth, verified, role, scope, demo),
 * exactly as the production sidebar route reaches it.
 */
class AiMemoryPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_memories_page_renders_for_workspace_admin(): void
    {
        $ctx = $this->createWorkspaceContext();

        AiMemory::create([
            'workspace_id' => $ctx['workspace']->id,
            'kind' => 'business',
            'content' => 'Pro plan is Rs 2999/month.',
            'usefulness' => 2,
            'source' => 'auto',
        ]);

        $response = $this->actingAs($ctx['user'])
            ->get(route('client.ai.memories.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('AI/Memories/Index')
            ->has('memories', 1)
            ->where('learningEnabled', true)
            ->where('maxMemories', AiMemory::MAX_MEMORIES)
            ->where('extractEvery', 20)
        );
    }

    public function test_manual_memory_can_be_added_and_deleted_over_http(): void
    {
        $ctx = $this->createWorkspaceContext();

        $this->actingAs($ctx['user'])
            ->post(route('client.ai.memories.store'), [
                'kind' => 'policy',
                'content' => 'We offer a 7-day money-back guarantee.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $memory = AiMemory::where('workspace_id', $ctx['workspace']->id)->first();
        $this->assertNotNull($memory);
        $this->assertSame('policy', $memory->kind);
        $this->assertSame('manual', $memory->source);

        $this->actingAs($ctx['user'])
            ->delete(route('client.ai.memories.destroy', $memory->id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNull($memory->fresh());
    }

    public function test_other_workspace_memory_cannot_be_deleted(): void
    {
        $ctx = $this->createWorkspaceContext();
        $other = $this->createWorkspaceContext();

        $memory = AiMemory::create([
            'workspace_id' => $other['workspace']->id,
            'kind' => 'fact',
            'content' => 'Other workspace secret.',
            'source' => 'auto',
        ]);

        $this->actingAs($ctx['user'])
            ->delete(route('client.ai.memories.destroy', $memory->id))
            ->assertForbidden();

        $this->assertNotNull($memory->fresh());
    }

    public function test_learning_toggle_persists_over_http(): void
    {
        $ctx = $this->createWorkspaceContext();

        $this->actingAs($ctx['user'])
            ->post(route('client.ai.memories.toggle'))
            ->assertRedirect();

        $this->actingAs($ctx['user'])
            ->get(route('client.ai.memories.index'))
            ->assertInertia(fn (Assert $page) => $page->where('learningEnabled', false));
    }
}
