<?php

namespace App\Modules\Instagram\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Instagram\Models\InstagramTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Saved Instagram message templates (generic carousel / button) — the Inbox
 * composer lists these, saves new ones, and deletes them. No Meta approval is
 * involved (unlike WhatsApp templates): these are just stored compositions of
 * the Graph generic/button template payload.
 */
class TemplateController extends Controller
{
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
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:generic,button'],
            'definition' => ['required', 'array'],
            // Button template
            'definition.text' => ['required_if:type,button', 'nullable', 'string', 'max:640'],
            'definition.buttons' => ['required_if:type,button', 'nullable', 'array', 'min:1', 'max:3'],
            'definition.buttons.*.type' => ['required_with:definition.buttons', 'in:web_url,postback'],
            'definition.buttons.*.title' => ['required_with:definition.buttons', 'string', 'max:20'],
            'definition.buttons.*.url' => ['required_if:definition.buttons.*.type,web_url', 'nullable', 'url', 'max:2048'],
            'definition.buttons.*.payload' => ['required_if:definition.buttons.*.type,postback', 'nullable', 'string', 'max:1000'],
            // Generic carousel
            'definition.elements' => ['required_if:type,generic', 'nullable', 'array', 'min:1', 'max:10'],
            'definition.elements.*.title' => ['required_if:type,generic', 'string', 'max:80'],
            'definition.elements.*.subtitle' => ['nullable', 'string', 'max:80'],
            'definition.elements.*.image_url' => ['nullable', 'url', 'max:2048'],
            'definition.elements.*.buttons' => ['nullable', 'array', 'max:3'],
            'definition.elements.*.buttons.*.type' => ['in:web_url,postback'],
            'definition.elements.*.buttons.*.title' => ['string', 'max:20'],
            'definition.elements.*.buttons.*.url' => ['nullable', 'url', 'max:2048'],
            'definition.elements.*.buttons.*.payload' => ['nullable', 'string', 'max:1000'],
        ]);

        // Normalise buttons: keep only the fields Graph expects for each type.
        $normaliseButtons = fn (array $buttons): array => collect($buttons)
            ->map(function (array $b): array {
                $out = ['type' => $b['type'], 'title' => $b['title']];

                if ($b['type'] === 'web_url') {
                    $out['url'] = $b['url'] ?? '';
                } else {
                    $out['payload'] = $b['payload'] ?? '';
                }

                return array_filter($out, fn ($v) => $v !== null && $v !== '');
            })
            ->filter(fn (array $b) => ($b['url'] ?? $b['payload'] ?? '') !== '')
            ->values()
            ->all();

        $definition = $data['definition'];

        if ($data['type'] === 'button') {
            $definition['buttons'] = $normaliseButtons((array) ($definition['buttons'] ?? []));
            unset($definition['elements']);
        } else {
            $definition['elements'] = collect((array) ($definition['elements'] ?? []))
                ->map(function (array $el) use ($normaliseButtons): array {
                    $out = ['title' => $el['title']];

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
                ->values()
                ->all();
            unset($definition['text'], $definition['buttons']);
        }

        return [
            'name' => $data['name'],
            'type' => $data['type'],
            'definition' => $definition,
        ];
    }

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }
}
