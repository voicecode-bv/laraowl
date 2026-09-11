import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

/**
 * Shared props that describe the workspace rather than the telemetry on
 * screen: the signed-in user, their teams and projects, and the update
 * banner. A live reload fires as often as data is ingested, and each one of
 * these costs a query while never changing between two ticks, so they are
 * left out of the partial reload and keep the value they already have.
 */
const WORKSPACE_PROPS = [
    'auth',
    'teams',
    'projects',
    'currentTeam',
    'currentProject',
    'update',
] as const;

export function useLiveReload(
    projectId: number | string | undefined,
    intervalMs = 5000,
): void {
    const lastReloadAt = useRef(0);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        if (!projectId || !window.Echo) {
            return;
        }

        const reload = () => {
            lastReloadAt.current = Date.now();
            router.reload({ except: [...WORKSPACE_PROPS] });
        };

        const schedule = () => {
            const elapsed = Date.now() - lastReloadAt.current;

            if (elapsed >= intervalMs) {
                if (timer.current) {
                    clearTimeout(timer.current);
                    timer.current = null;
                }

                reload();

                return;
            }

            if (!timer.current) {
                timer.current = setTimeout(() => {
                    timer.current = null;
                    reload();
                }, intervalMs - elapsed);
            }
        };

        const channel = window.Echo.private(`project.${projectId}`).listen(
            '.ProjectDataIngested',
            schedule,
        );

        return () => {
            if (timer.current) {
                clearTimeout(timer.current);
                timer.current = null;
            }

            channel.stopListening('.ProjectDataIngested');
        };
    }, [projectId, intervalMs]);
}
