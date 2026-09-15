import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

export type OfflineProject = {
    id: number;
    name: string;
    slug: string;
    url: string | null;
    since: string | null;
};

type UptimeChangedPayload = {
    project: {
        id: number;
        name: string;
        slug: string;
        url: string | null;
    };
    status: 'up' | 'down';
    status_code: number | null;
    error: string | null;
    changed_at: string;
};

/**
 * Which applications are down right now, kept current over the websocket.
 *
 * The server prop is the starting truth, so a dashboard opened in the middle
 * of an outage already knows — waiting for a transition would leave it blank
 * until something else broke. From there the team channel carries every
 * change: a `down` adds a row, an `up` removes it, and the banner follows.
 *
 * A change also raises a toast, but only for events that arrive while the
 * page is open. The ones already in the server prop are the state the user
 * walked into, not news, and toasting them on every page load would train
 * people to dismiss the thing without reading it.
 */
export function useUptimeAlerts(
    teamId: number | null | undefined,
    initial: OfflineProject[] | undefined,
): OfflineProject[] {
    const serverOffline = useMemo(() => initial ?? [], [initial]);
    const [live, setLive] = useState<OfflineProject[] | null>(null);

    // A later server render is newer than anything heard over the socket so
    // far, so it replaces the live list rather than being merged into it.
    const [renderedFrom, setRenderedFrom] = useState(serverOffline);

    if (renderedFrom !== serverOffline) {
        setRenderedFrom(serverOffline);
        setLive(null);
    }

    // The listener reads the server list through a ref so that a new prop
    // does not tear down and re-establish the subscription.
    const latestServer = useRef(serverOffline);

    useEffect(() => {
        latestServer.current = serverOffline;
    }, [serverOffline]);

    useEffect(() => {
        if (!teamId || !window.Echo) {
            return;
        }

        const onChange = (event: UptimeChangedPayload) => {
            setLive((current) => {
                const base = current ?? latestServer.current;
                const without = base.filter(
                    (offline) => offline.id !== event.project.id,
                );

                if (event.status !== 'down') {
                    return without;
                }

                return [
                    ...without,
                    {
                        id: event.project.id,
                        name: event.project.name,
                        slug: event.project.slug,
                        url: event.project.url,
                        since: event.changed_at,
                    },
                ];
            });

            if (event.status === 'down') {
                toast.error(`${event.project.name} is down`, {
                    description:
                        event.error ??
                        (event.status_code
                            ? `Responded with HTTP ${event.status_code}`
                            : undefined),
                    duration: Infinity,
                    id: `uptime-${event.project.id}`,
                });

                return;
            }

            toast.success(`${event.project.name} is back up`, {
                id: `uptime-${event.project.id}`,
                duration: 6000,
            });
        };

        const channel = window.Echo.private(`team.${teamId}`).listen(
            '.ProjectUptimeChanged',
            onChange,
        );

        return () => {
            channel.stopListening('.ProjectUptimeChanged');
        };
    }, [teamId]);

    return live ?? serverOffline;
}
