import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import AiMemoriesIndex from '@/Pages/AI/Memories/Index';

/**
 * Regression tests for the AI Memory page. The first test reproduces a real
 * production crash: EmptyState expects a rendered element for `icon`, not a
 * component class — passing one crashed the whole page via the error boundary
 * whenever the workspace had no memories yet.
 */
describe('AI Memories page', () => {
    const baseProps = {
        memories: [],
        learningEnabled: true,
        maxMemories: 200,
        extractEvery: 20,
    };

    it('renders the empty state without crashing when no memories exist', () => {
        render(<AiMemoriesIndex {...baseProps} />);

        expect(screen.getByText('ai.memory_title')).toBeInTheDocument();
        expect(screen.getByText('ai.memory_empty_title')).toBeInTheDocument();
    });

    it('renders memory rows with kind badges and usage counts', () => {
        render(<AiMemoriesIndex
            {...baseProps}
            memories={[{
                id: 1,
                kind: 'business',
                content: 'Pro plan is Rs 2999/month.',
                usefulness: 4,
                use_count: 7,
                source: 'auto',
                created_at: null,
            }]}
        />);

        expect(screen.getByText('Pro plan is Rs 2999/month.')).toBeInTheDocument();
        // The kind label appears both in the filter chips and the row badge.
        expect(screen.getAllByText('ai.memory_kind_business').length).toBeGreaterThan(0);
    });

    it('shows learning-off state', () => {
        render(<AiMemoriesIndex {...baseProps} learningEnabled={false} />);

        expect(screen.getByText('ai.memory_learning_off')).toBeInTheDocument();
    });
});
