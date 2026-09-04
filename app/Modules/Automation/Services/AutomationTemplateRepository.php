<?php

namespace App\Modules\Automation\Services;

class AutomationTemplateRepository
{
    /**
     * Get all available visual automation templates.
     */
    public static function getTemplates(): array
    {
        return [
            'ecommerce_order_placed' => [
                'name' => 'E-Commerce: Order Confirmation',
                'description' => 'Automatically send a WhatsApp message when a customer places an order.',
                'trigger_type' => 'order.placed',
                'nodes' => [
                    [
                        'id' => 'trigger_1',
                        'type' => 'trigger',
                        'position' => ['x' => 400, 'y' => 100],
                        'data' => [
                            'triggerType' => 'order.placed',
                            'label' => 'Order Placed',
                        ],
                    ],
                    [
                        'id' => 'node_1',
                        'type' => 'send_template',
                        'position' => ['x' => 400, 'y' => 250],
                        'data' => [
                            'nodeType' => 'send_template',
                            'label' => 'Send Order Template',
                            'template_name' => 'ecommerce_order_paid',
                            'language' => 'en',
                            'variables' => [
                                '{{contact.first_name}}',
                                '{{context.order_total}} {{context.order_currency}}',
                                '{{context.order_number}}',
                            ],
                        ],
                    ],
                ],
                'edges' => [
                    [
                        'id' => 'edge_trigger_1_node_1',
                        'source' => 'trigger_1',
                        'target' => 'node_1',
                        'type' => 'smoothstep',
                    ],
                ],
            ],
            'ecommerce_abandoned_cart' => [
                'name' => 'E-Commerce: Abandoned Cart Recovery',
                'description' => 'A multi-step sequence to recover abandoned carts using WhatsApp follow-ups and delays.',
                'trigger_type' => 'cart.abandoned',
                'nodes' => [
                    [
                        'id' => 'trigger_1',
                        'type' => 'trigger',
                        'position' => ['x' => 400, 'y' => 100],
                        'data' => [
                            'triggerType' => 'cart.abandoned',
                            'label' => 'Cart Abandoned',
                        ],
                    ],
                    [
                        'id' => 'node_1',
                        'type' => 'wait',
                        'position' => ['x' => 400, 'y' => 250],
                        'data' => [
                            'nodeType' => 'wait',
                            'label' => 'Wait 30 Minutes',
                            'amount' => 30,
                            'unit' => 'minutes',
                        ],
                    ],
                    [
                        'id' => 'node_2',
                        'type' => 'send_template',
                        'position' => ['x' => 400, 'y' => 400],
                        'data' => [
                            'nodeType' => 'send_template',
                            'label' => 'First Reminder Template',
                            'template_name' => 'ecommerce_abandoned_cart',
                            'language' => 'en',
                            'variables' => [
                                '{{contact.first_name}}',
                                '{{context.cart_total}} {{context.order_currency}}',
                                '{{context.recovery_url}}',
                            ],
                        ],
                    ],
                    [
                        'id' => 'node_3',
                        'type' => 'wait',
                        'position' => ['x' => 400, 'y' => 550],
                        'data' => [
                            'nodeType' => 'wait',
                            'label' => 'Wait 24 Hours',
                            'amount' => 24,
                            'unit' => 'hours',
                        ],
                    ],
                    [
                        'id' => 'node_4',
                        'type' => 'send_whatsapp',
                        'position' => ['x' => 400, 'y' => 700],
                        'data' => [
                            'nodeType' => 'send_whatsapp',
                            'label' => 'Final Offer',
                            'body' => "Hi {{contact.first_name}},\n\nYour cart is about to expire! As a special treat, use code SAVE10 for 10% off if you checkout today: {{context.recovery_url}}",
                        ],
                    ],
                ],
                'edges' => [
                    [
                        'id' => 'edge_trigger_1_node_1',
                        'source' => 'trigger_1',
                        'target' => 'node_1',
                        'type' => 'smoothstep',
                    ],
                    [
                        'id' => 'edge_node_1_node_2',
                        'source' => 'node_1',
                        'target' => 'node_2',
                        'type' => 'smoothstep',
                    ],
                    [
                        'id' => 'edge_node_2_node_3',
                        'source' => 'node_2',
                        'target' => 'node_3',
                        'type' => 'smoothstep',
                    ],
                    [
                        'id' => 'edge_node_3_node_4',
                        'source' => 'node_3',
                        'target' => 'node_4',
                        'type' => 'smoothstep',
                    ],
                ],
            ],
        ];
    }
}
