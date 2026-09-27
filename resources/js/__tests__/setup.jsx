import '@testing-library/jest-dom';

// Mock Inertia
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: { name: 'Test User', timezone: 'UTC' } }, timezone: 'UTC', flash: {} } }),
    router:  { visit: vi.fn(), delete: vi.fn(), post: vi.fn(), reload: vi.fn() },
    useForm: () => ({
        data: {},
        setData: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
        reset: vi.fn(),
        errors: {},
        processing: false,
    }),
    Head:    ({ title }) => null,
    Link:    ({ href, children, ...props }) => <a href={href} {...props}>{children}</a>,
}));

// Mock the route() helper (Ziggy): called with a name it returns a URL string;
// called with NO arguments (router-object usage in useClientNav) it returns
// the { has, current } shape the code relies on.
global.route = (name, params) => {
    if (name === undefined) {
        return { has: () => false, current: () => 'test.route' };
    }
    return `/${name}${params ? '/' + JSON.stringify(params) : ''}`;
};
