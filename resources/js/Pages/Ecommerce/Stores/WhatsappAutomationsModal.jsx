import React, { useState } from 'react';
import { Modal, Switch, Select, Button, Form, Typography, Space, Divider, message } from 'antd';
import { useForm } from '@inertiajs/react';

const { Title, Text } = Typography;

export default function WhatsappAutomationsModal({ store, visible, onClose, templates }) {
    const { data, setData, put, processing } = useForm({
        messaging_config: store?.messaging_config || {
            order_placed_cod: { enabled: false, template_id: null, variable_mapping: {} },
            order_placed_paid: { enabled: false, template_id: null, variable_mapping: {} },
            abandoned_cart_sequence: []
        }
    });

    // Default templates ship with fixed positional variables — show what
    // each will fill with real order data so the merchant knows what they
    // are approving.
    const VARIABLE_HINTS = {
        ecommerce_order_cod: '{{1}} name · {{2}} order # · {{3}} amount · {{4}} delivery date',
        ecommerce_order_paid: '{{1}} name · {{2}} amount · {{3}} order #',
        ecommerce_order_shipped: '{{1}} name · {{2}} order # · {{3}} delivery date · {{4}} tracking link',
        ecommerce_order_cancelled: '{{1}} name · {{2}} order #',
        ecommerce_order_confirmed: '{{1}} name · {{2}} order # · {{3}} amount',
        ecommerce_winback: '{{1}} name · {{2}} discount · {{3}} coupon code · {{4}} expiry',
        ecommerce_review_request: '{{1}} name · {{2}} order # · {{3}} review link',
        ecommerce_vip_thanks: '{{1}} name · {{2}} store name · {{3}} order #',
        ecommerce_abandoned_cart: '{{1}} name · {{2}} cart value · {{3}} recovery link',
    };

    const templateOptions = (templates || []).map(t => ({
        label: `${t.name} (${t.status?.toLowerCase?.() || t.status})`,
        value: t.id,
    }));

    const handleSave = () => {
        put(route('stores.update', store.uuid), {
            onSuccess: () => {
                message.success('WhatsApp Automations saved successfully!');
                onClose();
            }
        });
    };

    return (
        <Modal
            title="WhatsApp Automations for E-Commerce"
            open={visible}
            onCancel={onClose}
            onOk={handleSave}
            confirmLoading={processing}
            width={800}
            okText="Save Automations"
        >
            <div className="p-4">
                <Text type="secondary">
                    Configure automated WhatsApp messages for your Shopify/WooCommerce store. You don't need the visual Flow Builder for these!
                </Text>
                
                <Divider />

                <div className="mb-6">
                    <div className="flex justify-between items-center mb-2">
                        <Title level={5}>🛒 Order Confirmation (COD)</Title>
                        <Switch 
                            checked={data.messaging_config.order_placed_cod.enabled} 
                            onChange={(val) => setData('messaging_config', {
                                ...data.messaging_config,
                                order_placed_cod: { ...data.messaging_config.order_placed_cod, enabled: val }
                            })}
                        />
                    </div>
                    {data.messaging_config.order_placed_cod.enabled && (
                        <div className="bg-gray-50 p-4 rounded-md">
                            <Form.Item label="Select WhatsApp Template">
                                <Select 
                                    className="w-full"
                                    placeholder="Select a Meta approved template"
                                    options={templateOptions}
                                    value={data.messaging_config.order_placed_cod.template_id}
                                    onChange={(val) => setData('messaging_config', {
                                        ...data.messaging_config,
                                        order_placed_cod: { ...data.messaging_config.order_placed_cod, template_id: val }
                                    })}
                                />
                            </Form.Item>
                            <TemplateVariableHint templates={templates} selectedId={data.messaging_config.order_placed_cod.template_id} hints={VARIABLE_HINTS} />
                        </div>
                    )}
                </div>

                <Divider />

                <div className="mb-6">
                    <div className="flex justify-between items-center mb-2">
                        <Title level={5}>💳 Order Confirmation (Paid)</Title>
                        <Switch 
                            checked={data.messaging_config.order_placed_paid?.enabled} 
                            onChange={(val) => setData('messaging_config', {
                                ...data.messaging_config,
                                order_placed_paid: { ...data.messaging_config.order_placed_paid, enabled: val }
                            })}
                        />
                    </div>
                    {data.messaging_config.order_placed_paid?.enabled && (
                        <div className="bg-gray-50 p-4 rounded-md">
                            <Form.Item label="Select WhatsApp Template">
                                <Select 
                                    className="w-full"
                                    placeholder="Select a Meta approved template"
                                    options={templateOptions}
                                    value={data.messaging_config.order_placed_paid?.template_id}
                                    onChange={(val) => setData('messaging_config', {
                                        ...data.messaging_config,
                                        order_placed_paid: { ...data.messaging_config.order_placed_paid, template_id: val }
                                    })}
                                />
                            </Form.Item>
                            <TemplateVariableHint templates={templates} selectedId={data.messaging_config.order_placed_paid?.template_id} hints={VARIABLE_HINTS} />
                        </div>
                    )}
                </div>

                <Divider />

                <div className="mb-6">
                    <div className="flex justify-between items-center mb-2">
                        <Title level={5}>🛍️ Abandoned Cart Sequence</Title>
                        <Button type="dashed" onClick={() => {
                            const currentSeq = data.messaging_config.abandoned_cart_sequence || [];
                            setData('messaging_config', {
                                ...data.messaging_config,
                                abandoned_cart_sequence: [...currentSeq, { delay_minutes: 30, template_id: null, variable_mapping: {} }]
                            });
                        }}>
                            + Add Follow-up Message
                        </Button>
                    </div>
                    
                    {data.messaging_config.abandoned_cart_sequence?.map((step, index) => (
                        <div key={index} className="bg-orange-50 p-4 rounded-md mb-2 border border-orange-200">
                            <Space direction="vertical" className="w-full">
                                <Text strong>Follow-up #{index + 1}</Text>
                                <div className="flex gap-4">
                                    <Form.Item label="Delay (Minutes)" className="mb-0 flex-1">
                                        <Select 
                                            options={[
                                                {label: '30 Minutes', value: 30},
                                                {label: '2 Hours', value: 120},
                                                {label: '24 Hours', value: 1440}
                                            ]}
                                            value={step.delay_minutes}
                                            onChange={(val) => {
                                                const newSeq = [...data.messaging_config.abandoned_cart_sequence];
                                                newSeq[index].delay_minutes = val;
                                                setData('messaging_config', { ...data.messaging_config, abandoned_cart_sequence: newSeq });
                                            }}
                                        />
                                    </Form.Item>
                                    <Form.Item label="Template" className="mb-0 flex-2 w-full">
                                        <Select 
                                            className="w-full"
                                            placeholder="Select template"
                                            options={templateOptions}
                                            value={step.template_id}
                                            onChange={(val) => {
                                                const newSeq = [...data.messaging_config.abandoned_cart_sequence];
                                                newSeq[index].template_id = val;
                                                setData('messaging_config', { ...data.messaging_config, abandoned_cart_sequence: newSeq });
                                            }}
                                        />
                                    </Form.Item>
                                    <Button danger onClick={() => {
                                        const newSeq = data.messaging_config.abandoned_cart_sequence.filter((_, i) => i !== index);
                                        setData('messaging_config', { ...data.messaging_config, abandoned_cart_sequence: newSeq });
                                    }}>Remove</Button>
                                </div>
                            </Space>
                        </div>
                    ))}
                </div>
            </div>
        </Modal>
    );
}

function TemplateVariableHint({ templates, selectedId, hints }) {
    const template = (templates || []).find(t => t.id === selectedId);
    const hint = template ? hints[template.name] : null;

    if (!hint) return null;

    return (
        <Text type="secondary" style={{ fontSize: 12 }}>
            Fills automatically with real order data: {hint}
        </Text>
    );
}
