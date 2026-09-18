<?php

namespace Tests\Feature\Instagram;

use App\Modules\Instagram\Models\InstagramAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RecentPostsPaginationTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private InstagramAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ctx = $this->createWorkspaceContext();

        $this->account = InstagramAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'ig_user_id' => '17841400000001',
            'username' => 'myshop',
            'display_name' => 'My Shop',
            'page_token' => 'page-token-secret',
            'status' => 'active',
        ]);
    }

    private function mediaItem(int $n): array
    {
        return [
            'id' => 'media_'.$n,
            'caption' => 'Post number '.$n,
            'media_product_type' => 'REEL',
            'media_url' => 'https://cdn.example.com/'.$n.'.jpg',
            'permalink' => 'https://instagram.com/p/media_'.$n,
            'timestamp' => sprintf('2026-09-%02dT12:00:00+0000', (($n - 1) % 28) + 1),
            'thumbnail_url' => 'https://cdn.example.com/'.$n.'_thumb.jpg',
        ];
    }

    private function graphResponse(array $items, ?string $after): array
    {
        $payload = ['data' => $items];

        if ($after !== null) {
            $payload['paging'] = ['cursors' => ['after' => $after]];
        }

        return $payload;
    }

    public function test_guests_cannot_list_recent_posts(): void
    {
        Http::fake();

        $this->getJson(route('client.instagram.automations.recent-posts', [
            'account_id' => $this->account->id,
        ]))->assertStatus(401);

        Http::assertNothingSent();
    }

    public function test_recent_posts_returns_first_page_with_next_cursor(): void
    {
        Http::fake([
            'graph.instagram.com/*' => Http::response($this->graphResponse(
                [$this->mediaItem(1), $this->mediaItem(2)],
                'cursor-page-2'
            ), 200),
        ]);

        $response = $this->actingAs($this->ctx['user'])
            ->getJson(route('client.instagram.automations.recent-posts', [
                'account_id' => $this->account->id,
            ]));

        $response->assertOk()
            ->assertJsonCount(2, 'posts')
            ->assertJsonPath('next_cursor', 'cursor-page-2')
            ->assertJsonPath('posts.0.id', 'media_1')
            ->assertJsonPath('posts.0.timestamp', '2026-09-01T12:00:00+0000');

        Http::assertSent(fn ($request) => ! isset($request['after']));
    }

    public function test_recent_posts_forwards_cursor_to_graph(): void
    {
        Http::fake([
            'graph.instagram.com/*' => Http::response($this->graphResponse(
                [$this->mediaItem(25), $this->mediaItem(26)],
                null
            ), 200),
        ]);

        $response = $this->actingAs($this->ctx['user'])
            ->getJson(route('client.instagram.automations.recent-posts', [
                'account_id' => $this->account->id,
                'cursor' => 'cursor-page-2',
            ]));

        $response->assertOk()
            ->assertJsonCount(2, 'posts')
            ->assertJsonPath('next_cursor', null);

        Http::assertSent(function ($request) {
            return isset($request['after']) && $request['after'] === 'cursor-page-2';
        });
    }

    public function test_recent_posts_cannot_read_other_workspaces_accounts(): void
    {
        Http::fake();

        $other = InstagramAccount::create([
            'workspace_id' => $this->createWorkspaceContext()['workspace']->id,
            'ig_user_id' => '17841400000002',
            'username' => 'other',
            'page_token' => 'other-token',
            'status' => 'active',
        ]);

        $this->actingAs($this->ctx['user'])
            ->getJson(route('client.instagram.automations.recent-posts', [
                'account_id' => $other->id,
            ]))
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_recent_posts_maps_graph_error_to_502(): void
    {
        Http::fake([
            'graph.instagram.com/*' => Http::response([
                'error' => ['message' => 'Access token expired', 'code' => 190],
            ], 401),
        ]);

        $this->actingAs($this->ctx['user'])
            ->getJson(route('client.instagram.automations.recent-posts', [
                'account_id' => $this->account->id,
            ]))
            ->assertStatus(502)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'Access token expired'));
    }
}
