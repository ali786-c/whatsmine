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
                'name' => 'E-Commerce: Order Confirmation & COD Verify',
                'description' => 'Automatically verify COD orders with quick replies, and send instant confirmation for prepaid orders.',
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
                        'id' => 'node_condition',
                        'type' => 'condition',
                        'position' => ['x' => 400, 'y' => 250],
                        'data' => [
                            'nodeType' => 'condition',
                            'label' => 'Is COD?',
                            'field' => 'context.payment_method',
                            'operator' => '==',
                            'value' => 'COD',
                        ],
                    ],
                    [
                        'id' => 'node_cod',
                        'type' => 'send_template',
                        'position' => ['x' => 200, 'y' => 400],
                        'data' => [
                            'nodeType' => 'send_template',
                            'label' => 'Verify COD Template',
                            'template_name' => 'ecommerce_order_cod',
                            'language' => 'en',
                            'variables' => [
                                '{{contact.first_name}}',
                                '{{context.order_number}}',
                                '{{context.order_total}} {{context.order_currency}}',
                            ],
                        ],
                    ],
                    [
                        'id' => 'node_prepaid',
                        'type' => 'send_template',
                        'position' => ['x' => 600, 'y' => 400],
                        'data' => [
                            'nodeType' => 'send_template',
                            'label' => 'Prepaid Confirm Template',
                            'template_name' => 'ecommerce_order_paid',
                            'language' => 'en',
                            'variables' => [
                                '{{contact.first_name}}',
                                '{{context.order_total}} {{context.order_currency}}',
                                '{{context.order_number}}',
                            ],
                        ],
                    ],
                    [
                        'id' => 'node_cod_wait',
                        'type' => 'wait',
                        'position' => ['x' => 200, 'y' => 550],
                        'data' => [
                            'nodeType' => 'wait',
                            'label' => 'Wait for Reply',
                            'wait_type' => 'reply',
                            'variable' => 'cod_reply',
                        ],
                    ],
                    [
                        'id' => 'node_cod_check',
                        'type' => 'condition',
                        'position' => ['x' => 200, 'y' => 700],
                        'data' => [
                            'nodeType' => 'condition',
                            'label' => 'Did they confirm?',
                            'field' => 'context.cod_reply',
                            'operator' => 'contains',
                            'value' => 'Confirm',
                        ],
                    ],
                    [
                        'id' => 'node_cod_confirm',
                        'type' => 'send_whatsapp',
                        'position' => ['x' => 0, 'y' => 850],
                        'data' => [
                            'nodeType' => 'send_whatsapp',
                            'label' => 'Send Confirmation',
                            'body' => "Thank you {{contact.first_name}}! ✅\n\nYour COD order #{{context.order_number}} for {{context.order_total}} {{context.order_currency}} has been confirmed and is now being processed for delivery.",
                        ],
                    ],
                    [
                        'id' => 'node_cod_cancel',
                        'type' => 'send_whatsapp',
                        'position' => ['x' => 400, 'y' => 850],
                        'data' => [
                            'nodeType' => 'send_whatsapp',
                            'label' => 'Send Cancellation',
                            'body' => "We understand, {{contact.first_name}}.\n\nYour order #{{context.order_number}} has been cancelled as requested. ❌\n\nLet us know if you need any help!",
                        ],
                    ],
                ],
                'edges' => [
                    [
                        'id' => 'edge_trigger_to_cond',
                        'source' => 'trigger_1',
                        'target' => 'node_condition',
                        'type' => 'smoothstep',
                    ],
                    [
                        'id' => 'edge_cond_true',
                        'source' => 'node_condition',
                        'target' => 'node_cod',
                        'sourceHandle' => 'true',
                        'type' => 'smoothstep',
                    ],
                    [
                        'id' => 'edge_cond_false',
                        'source' => 'node_condition',
                        'target' => 'node_prepaid',
                        'sourceHandle' => 'false',
                        'type' => 'smoothstep',
                    ],
                    [
                        'id' => 'edge_cod_to_wait',
                        'source' => 'node_cod',
                        'target' => 'node_cod_wait',
                        'type' => 'smoothstep',
                    ],
                    [
                        'id' => 'edge_wait_to_check',
                        'source' => 'node_cod_wait',
                        'target' => 'node_cod_check',
                        'type' => 'smoothstep',
                    ],
                    [
                        'id' => 'edge_check_true',
                        'source' => 'node_cod_check',
                        'target' => 'node_cod_confirm',
                        'sourceHandle' => 'true',
                        'type' => 'smoothstep',
                    ],
                    [
                        'id' => 'edge_check_false',
                        'source' => 'node_cod_check',
                        'target' => 'node_cod_cancel',
                        'sourceHandle' => 'false',
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
                        'type' => 'send_template',
                        'position' => ['x' => 400, 'y' => 700],
                        'data' => [
                            'nodeType' => 'send_template',
                            'label' => 'Final Offer',
                            'template_name' => 'ecommerce_abandoned_cart',
                            'language' => 'en',
                            'variables' => [
                                '{{contact.first_name}}',
                                '{{context.cart_total}} {{context.order_currency}}',
                                '{{context.recovery_url}}',
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
            'ecommerce_multi_platform_routing' => [
                'name' => 'E-Commerce: WooCommerce & Shopify Routing',
                'description' => 'A smart template that checks if an order came from WooCommerce or Shopify, and routes to a different message.',
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
                        'id' => 'node_condition',
                        'type' => 'condition',
                        'position' => ['x' => 400, 'y' => 250],
                        'data' => [
                            'nodeType' => 'condition',
                            'label' => 'Is WooCommerce?',
                            'field' => 'context.platform',
                            'operator' => '==',
                            'value' => 'woocommerce',
                        ],
                    ],
                    [
                        'id' => 'node_woo',
                        'type' => 'send_template',
                        'position' => ['x' => 200, 'y' => 400],
                        'data' => [
                            'nodeType' => 'send_template',
                            'label' => 'WooCommerce Order Template',
                            'template_name' => 'ecommerce_order_paid',
                            'language' => 'en',
                            'variables' => [
                                '{{contact.first_name}}',
                                '{{context.order_total}} {{context.order_currency}}',
                                '{{context.order_number}}',
                            ],
                        ],
                    ],
                    [
                        'id' => 'node_shopify',
                        'type' => 'send_template',
                        'position' => ['x' => 600, 'y' => 400],
                        'data' => [
                            'nodeType' => 'send_template',
                            'label' => 'Shopify Order Template',
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
                        'id' => 'edge_trigger_to_cond',
                        'source' => 'trigger_1',
                        'target' => 'node_condition',
                        'type' => 'smoothstep',
                    ],
                    [
                        'id' => 'edge_cond_true',
                        'source' => 'node_condition',
                        'target' => 'node_woo',
                        'sourceHandle' => 'true',
                        'type' => 'smoothstep',
                    ],
                    [
                        'id' => 'edge_cond_false',
                        'source' => 'node_condition',
                        'target' => 'node_shopify',
                        'sourceHandle' => 'false',
                        'type' => 'smoothstep',
                    ],
                ],
            ],
            'ecommerce_shipping_notification' => [
                'name' => 'E-Commerce: Shipping & Tracking Notification',
                'description' => 'Send an automatic WhatsApp message with tracking details when an order is shipped.',
                'trigger_type' => 'order.fulfilled',
                'nodes' => [
                    [
                        'id' => 'trigger_1',
                        'type' => 'trigger',
                        'position' => ['x' => 400, 'y' => 100],
                        'data' => [
                            'triggerType' => 'order.fulfilled',
                            'label' => 'Order Shipped',
                        ],
                    ],
                    [
                        'id' => 'node_1',
                        'type' => 'send_template',
                        'position' => ['x' => 400, 'y' => 250],
                        'data' => [
                            'nodeType' => 'send_template',
                            'label' => 'Shipping Template',
                            'template_name' => 'ecommerce_order_shipped',
                            'language' => 'en',
                            'variables' => [
                                '{{contact.first_name}}',
                                '{{context.order_number}}',
                                '{{context.tracking_url}}',
                            ],
                        ],
                    ],
                ],
                'edges' => [
                    [
                        'id' => 'edge_1',
                        'source' => 'trigger_1',
                        'target' => 'node_1',
                        'type' => 'smoothstep',
                    ],
                ],
            ],
            'ecommerce_winback' => [
                'name' => 'E-Commerce: Customer Win-back',
                'description' => 'Automatically send a 15% discount code to customers 30 days after their purchase to drive repeat sales.',
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
                        'id' => 'node_wait',
                        'type' => 'wait',
                        'position' => ['x' => 400, 'y' => 250],
                        'data' => [
                            'nodeType' => 'wait',
                            'label' => 'Wait 30 Days',
                            'amount' => 30,
                            'unit' => 'days',
                        ],
                    ],
                    [
                        'id' => 'node_send',
                        'type' => 'send_template',
                        'position' => ['x' => 400, 'y' => 400],
                        'data' => [
                            'nodeType' => 'send_template',
                            'label' => 'Win-back Template',
                            'template_name' => 'ecommerce_winback',
                            'language' => 'en',
                            'variables' => [
                                '{{contact.first_name}}',
                                'WELCOMEBACK15',
                                '15%',
                            ],
                        ],
                    ],
                ],
                'edges' => [
                    [
                        'id' => 'edge_1',
                        'source' => 'trigger_1',
                        'target' => 'node_wait',
                        'type' => 'smoothstep',
                    ],
                    [
                        'id' => 'edge_2',
                        'source' => 'node_wait',
                        'target' => 'node_send',
                        'type' => 'smoothstep',
                    ],
                ],
            ],
            'ecommerce_review_request' => [
                'name' => 'E-Commerce: Product Review Request',
                'description' => 'Ask customers for a review 3 days after their order is shipped/fulfilled.',
                'trigger_type' => 'order.fulfilled',
                'nodes' => [
                    [
                        'id' => 'trigger_1',
                        'type' => 'trigger',
                        'position' => ['x' => 400, 'y' => 100],
                        'data' => [
                            'triggerType' => 'order.fulfilled',
                            'label' => 'Order Shipped',
                        ],
                    ],
                    [
                        'id' => 'node_wait',
                        'type' => 'wait',
                        'position' => ['x' => 400, 'y' => 250],
                        'data' => [
                            'nodeType' => 'wait',
                            'label' => 'Wait 3 Days',
                            'amount' => 3,
                            'unit' => 'days',
                        ],
                    ],
                    [
                        'id' => 'node_send',
                        'type' => 'send_template',
                        'position' => ['x' => 400, 'y' => 400],
                        'data' => [
                            'nodeType' => 'send_template',
                            'label' => 'Review Template',
                            'template_name' => 'ecommerce_review_request',
                            'language' => 'en',
                            'variables' => [
                                'https://your-store.com/reviews',
                            ],
                        ],
                    ],
                ],
                'edges' => [
                    [
                        'id' => 'edge_1',
                        'source' => 'trigger_1',
                        'target' => 'node_wait',
                        'type' => 'smoothstep',
                    ],
                    [
                        'id' => 'edge_2',
                        'source' => 'node_wait',
                        'target' => 'node_send',
                        'type' => 'smoothstep',
                    ],
                ],
            ],
            'ecommerce_vip_thanks' => [
                'name' => 'E-Commerce: VIP Founder\'s Thank You',
                'description' => 'Send a personalized thank you message from the founder to customers who spend more than 15,000.',
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
                        'id' => 'node_cond',
                        'type' => 'condition',
                        'position' => ['x' => 400, 'y' => 250],
                        'data' => [
                            'nodeType' => 'condition',
                            'label' => 'Order > 15k?',
                            'field' => 'context.order_total',
                            'operator' => '>',
                            'value' => '15000',
                        ],
                    ],
                    [
                        'id' => 'node_send',
                        'type' => 'send_template',
                        'position' => ['x' => 200, 'y' => 400],
                        'data' => [
                            'nodeType' => 'send_template',
                            'label' => 'VIP Template',
                            'template_name' => 'ecommerce_vip_thanks',
                            'language' => 'en',
                            'variables' => [
                                '{{contact.first_name}}',
                                '{{context.order_number}}',
                            ],
                        ],
                    ],
                ],
                'edges' => [
                    [
                        'id' => 'edge_1',
                        'source' => 'trigger_1',
                        'target' => 'node_cond',
                        'type' => 'smoothstep',
                    ],
                    [
                        'id' => 'edge_2',
                        'source' => 'node_cond',
                        'target' => 'node_send',
                        'sourceHandle' => 'true',
                        'type' => 'smoothstep',
                    ],
                ],
            ],
        ];
    }
}
