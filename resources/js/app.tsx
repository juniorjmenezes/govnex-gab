import { createInertiaApp } from '@inertiajs/react';
import { StrictMode } from 'react';
import type { ReactNode } from 'react';
import { createRoot, hydrateRoot } from 'react-dom/client';
import type { Root } from 'react-dom/client';
import { PwaUpdatePrompt } from '@/components/layout/pwa-update-prompt';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import { useFlashToast } from '@/hooks/use-flash-toast';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'GOVNEX GAB';

declare global {
    interface Window {
        __govnexGabReactRoot?: Root;
    }
}

function FlashToastListener() {
    useFlashToast();

    return null;
}

function AppProviders({ children }: { children: ReactNode }) {
    return (
        <TooltipProvider delayDuration={0}>
            {children}
            <FlashToastListener />
            <Toaster />
            <PwaUpdatePrompt />
        </TooltipProvider>
    );
}

initializeTheme();

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return null;
            case name.startsWith('auth/') ||
                name.startsWith('entity-invitations/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    setup({ el, App, props }) {
        const application = (
            <StrictMode>
                <AppProviders>
                    <App {...props} />
                </AppProviders>
            </StrictMode>
        );

        if (!el) {
            return application;
        }

        if (window.__govnexGabReactRoot) {
            window.__govnexGabReactRoot.render(application);

            return;
        }

        if (el.hasAttribute('data-server-rendered')) {
            window.__govnexGabReactRoot = hydrateRoot(el, application);

            return;
        }

        const root = createRoot(el);
        root.render(application);
        window.__govnexGabReactRoot = root;
    },
    progress: {
        color: '#4B5563',
    },
});
