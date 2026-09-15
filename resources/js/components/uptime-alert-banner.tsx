import { Link } from '@inertiajs/react';
import { ArrowUpRight, TriangleAlert } from 'lucide-react';
import type { OfflineProject } from '@/hooks/use-uptime-alerts';

function sinceLabel(since: string | null): string | null {
    if (!since) {
        return null;
    }

    const elapsed = Date.now() - new Date(since).getTime();

    if (Number.isNaN(elapsed) || elapsed < 0) {
        return null;
    }

    const minutes = Math.floor(elapsed / 60000);

    if (minutes < 1) {
        return 'just now';
    }

    if (minutes < 60) {
        return `${minutes}m`;
    }

    const hours = Math.floor(minutes / 60);

    return hours < 24 ? `${hours}h` : `${Math.floor(hours / 24)}d`;
}

/**
 * The standing alarm for applications that are not responding.
 *
 * It sits above the dashboard rather than inside the availability card,
 * because an outage is the one thing on this screen that should be readable
 * without having scrolled to the right section. It says which applications,
 * so it is actionable on sight, and it disappears by itself when the last one
 * recovers — the same event that removed the row.
 */
export function UptimeAlertBanner({
    offline,
    uptimeHref,
}: {
    offline: OfflineProject[];
    uptimeHref: string;
}) {
    if (offline.length === 0) {
        return null;
    }

    return (
        <div
            role="alert"
            aria-live="assertive"
            className="flex flex-wrap items-center gap-x-4 gap-y-3 rounded-xl border border-red-500/40 bg-red-500/10 px-4 py-3"
        >
            <span className="flex items-center gap-2.5">
                <TriangleAlert className="size-4 shrink-0 text-red-500" />
                <span className="text-[11px] font-black tracking-widest text-red-500 uppercase">
                    {offline.length === 1
                        ? '1 application down'
                        : `${offline.length} applications down`}
                </span>
            </span>

            <span className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1.5">
                {offline.map((project) => {
                    const elapsed = sinceLabel(project.since);

                    return (
                        <span
                            key={project.id}
                            className="flex items-center gap-1.5 rounded-md bg-red-500/10 px-2 py-1"
                        >
                            <span className="max-w-[180px] truncate text-[11px] font-black tracking-widest text-foreground uppercase">
                                {project.name}
                            </span>
                            {elapsed && (
                                <span className="text-[10px] font-medium text-muted-foreground/70 tabular-nums">
                                    {elapsed}
                                </span>
                            )}
                        </span>
                    );
                })}
            </span>

            <Link
                href={uptimeHref}
                className="ml-auto flex shrink-0 items-center gap-1.5 text-[10px] font-black tracking-widest text-red-500 uppercase transition-opacity hover:opacity-70"
            >
                Investigate <ArrowUpRight className="size-3" />
            </Link>
        </div>
    );
}
