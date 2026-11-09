import defaultTheme from 'tailwindcss/defaultTheme';
import animate from 'tailwindcss-animate';

/**
 * Take Care Connect Admin — design system.
 *
 * Every colour resolves to a CSS variable declared in resources/css/app.css.
 * Components must use these token names (bg-primary, text-muted-foreground,
 * bg-stage-won, ...) and NEVER a literal hex value. That single rule is what
 * made the switch to the current blue-on-white palette a token edit, and it is
 * also what makes dark mode a one-file change rather than a
 * component-by-component audit.
 *
 * @type {import('tailwindcss').Config}
 */
export default {
    /*
     | Enabled from day one even though v1 ships light-only.
     |
     | With no .dark rules and no toggle this compiles to nothing, so it costs
     | zero bytes today. Turning it on later would otherwise mean editing this
     | file AND re-auditing every component that had hard-coded a light colour.
     */
    darkMode: 'class',
    content: [
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.{js,ts,jsx,tsx}',
    ],
    theme: {
        /*
         | No container block, unlike the public site.
         |
         | The site caps at 1400px because it is a document. An admin is a
         | workspace: tables, boards and dashboards use the full viewport.
         */
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                border: 'hsl(var(--border))',
                input: 'hsl(var(--input))',
                ring: 'hsl(var(--ring))',
                background: 'hsl(var(--background))',
                foreground: 'hsl(var(--foreground))',
                /** Alternating section background (#F5F7FB), inherited from the site. */
                surface: 'hsl(var(--surface))',
                /** The workspace canvas a card sits on. */
                app: 'hsl(var(--app))',
                overlay: 'hsl(var(--overlay) / <alpha-value>)',
                primary: {
                    DEFAULT: 'hsl(var(--primary))',
                    dark: 'hsl(var(--primary-dark))',
                    foreground: 'hsl(var(--primary-foreground))',
                },
                /** Warm red — destructive and true CTAs only, never a status chip. */
                accent: {
                    DEFAULT: 'hsl(var(--accent))',
                    dark: 'hsl(var(--accent-dark))',
                    foreground: 'hsl(var(--accent-foreground))',
                },
                success: {
                    DEFAULT: 'hsl(var(--success))',
                    foreground: 'hsl(var(--success-foreground))',
                },
                /** Waiting, not failing. See the note in app.css. */
                warning: {
                    DEFAULT: 'hsl(var(--warning))',
                    foreground: 'hsl(var(--warning-foreground))',
                    soft: 'hsl(var(--warning-soft))',
                },
                info: {
                    DEFAULT: 'hsl(var(--info))',
                    foreground: 'hsl(var(--info-foreground))',
                },
                muted: {
                    DEFAULT: 'hsl(var(--muted))',
                    foreground: 'hsl(var(--muted-foreground))',
                },
                sidebar: {
                    DEFAULT: 'hsl(var(--sidebar))',
                    deep: 'hsl(var(--sidebar-deep))',
                    foreground: 'hsl(var(--sidebar-foreground))',
                    heading: 'hsl(var(--sidebar-heading))',
                    section: 'hsl(var(--sidebar-section))',
                    hover: 'hsl(var(--sidebar-hover))',
                    active: 'hsl(var(--sidebar-active))',
                    'active-foreground': 'hsl(var(--sidebar-active-foreground))',
                    border: 'hsl(var(--sidebar-border))',
                },
                card: {
                    DEFAULT: 'hsl(var(--card))',
                    foreground: 'hsl(var(--card-foreground))',
                },
                popover: {
                    DEFAULT: 'hsl(var(--popover))',
                    foreground: 'hsl(var(--popover-foreground))',
                },
                destructive: {
                    DEFAULT: 'hsl(var(--destructive))',
                    foreground: 'hsl(var(--destructive-foreground))',
                },
                /*
                 | Workflow stage colours, deliberately FLAT rather than nested.
                 |
                 | bg-stage-won, text-stage-review and border-stage-lost must all
                 | resolve, and a nested object would force bg-stage-won-DEFAULT.
                 | Tailwind cannot scan a class name built by interpolation, so
                 | config/stages.ts maps each stage key to a literal class string.
                 */
                stage: {
                    /* Sales pipeline — one ramp cooling toward the brand navy. */
                    intake: 'hsl(var(--stage-intake))',
                    cold: 'hsl(var(--stage-cold))',
                    warm: 'hsl(var(--stage-warm))',
                    qualified: 'hsl(var(--stage-qualified))',
                    proposal: 'hsl(var(--stage-proposal))',
                    won: 'hsl(var(--stage-won))',
                    lost: 'hsl(var(--stage-lost))',
                    /* Interview + article workflow — a separate hue family. */
                    scheduled: 'hsl(var(--stage-scheduled))',
                    rescheduled: 'hsl(var(--stage-rescheduled))',
                    conducted: 'hsl(var(--stage-conducted))',
                    'handed-over': 'hsl(var(--stage-handed-over))',
                    'in-content': 'hsl(var(--stage-in-content))',
                    assigned: 'hsl(var(--stage-assigned))',
                    drafting: 'hsl(var(--stage-drafting))',
                    'internal-review': 'hsl(var(--stage-internal-review))',
                    revisions: 'hsl(var(--stage-revisions))',
                    draft: 'hsl(var(--stage-draft))',
                    'pending-review': 'hsl(var(--stage-pending-review))',
                    published: 'hsl(var(--stage-published))',
                    cancelled: 'hsl(var(--stage-cancelled))',
                    'no-show': 'hsl(var(--stage-no-show))',
                    /* Creative deliverables. */
                    pending: 'hsl(var(--stage-pending))',
                    'in-progress': 'hsl(var(--stage-in-progress))',
                    review: 'hsl(var(--stage-review))',
                    approved: 'hsl(var(--stage-approved))',
                    rejected: 'hsl(var(--stage-rejected))',
                },
                chart: {
                    1: 'hsl(var(--chart-1))',
                    2: 'hsl(var(--chart-2))',
                    3: 'hsl(var(--chart-3))',
                    4: 'hsl(var(--chart-4))',
                    5: 'hsl(var(--chart-5))',
                    6: 'hsl(var(--chart-6))',
                },
            },
            borderRadius: {
                lg: 'var(--radius)',
                md: 'calc(var(--radius) - 2px)',
                sm: 'calc(var(--radius) - 4px)',
                xl: 'calc(var(--radius) + 4px)',
                '2xl': 'calc(var(--radius) + 8px)',
                /** Inputs, buttons, chips. At 12px a dense control row reads as bubbles. */
                control: 'var(--radius-control)',
            },
            boxShadow: {
                card: '0 1px 2px 0 rgb(10 13 20 / 0.03)',
                xs: '0 1px 2px 0 rgb(10 13 20 / 0.03)',
                'card-hover': '0 2px 4px 0 rgb(10 13 20 / 0.04), 0 12px 28px -6px rgb(10 13 20 / 0.10)',
            },
            keyframes: {
                'accordion-down': {
                    from: { height: '0' },
                    to: { height: 'var(--radix-accordion-content-height)' },
                },
                'accordion-up': {
                    from: { height: 'var(--radix-accordion-content-height)' },
                    to: { height: '0' },
                },
            },
            animation: {
                'accordion-down': 'accordion-down 0.2s ease-out',
                'accordion-up': 'accordion-up 0.2s ease-out',
            },
        },
    },
    plugins: [animate],
};
