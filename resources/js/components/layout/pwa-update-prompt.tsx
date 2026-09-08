import { useEffect, useState } from 'react';
import { toast } from 'sonner';

export function PwaUpdatePrompt() {
    const [registration, setRegistration] =
        useState<ServiceWorkerRegistration | null>(null);

    useEffect(() => {
        if (!('serviceWorker' in navigator) || !import.meta.env.PROD) {
            return;
        }

        const register = async () => {
            const worker = await navigator.serviceWorker.register('/sw.js');

            if (worker.waiting) {
                setRegistration(worker);
            }

            worker.addEventListener('updatefound', () => {
                const installing = worker.installing;
                installing?.addEventListener('statechange', () => {
                    if (
                        installing.state === 'installed' &&
                        navigator.serviceWorker.controller
                    ) {
                        setRegistration(worker);
                    }
                });
            });
        };

        register().catch(() => {
            // O aplicativo continua funcional quando o navegador bloqueia PWA.
        });
    }, []);

    useEffect(() => {
        if (!registration) {
            return;
        }

        toast.info('Uma atualização do GOVNEX GAB está disponível.', {
            duration: Infinity,
            action: {
                label: 'Atualizar',
                onClick: () => window.location.reload(),
            },
        });
    }, [registration]);

    return null;
}
