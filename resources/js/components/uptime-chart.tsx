import { Globe } from 'lucide-react';
import { useMemo, useState } from 'react';
import { cn } from '@/lib/utils';

/**
 * The status palette: three fixed steps that encode a state, not an identity.
 *
 * Stacking applications on one axis meant colour had to say *which*
 * application a segment belonged to, which is eight hues to tell apart inside
 * a single bar. A row per application says that in text instead, so colour is
 * free to carry the only thing a band has to show at a glance: how a slot
 * went. These are reserved for that and never used as series colours.
 *
 * Three, not four: the palette's `serious` step sits 13.6 ΔE from `warning`,
 * below the floor at which full-colour vision reliably separates a pair, so
 * grading partial failures into two shades would have re-created the problem
 * this chart was rebuilt to fix. These three measure 27.6 ΔE at their worst
 * adjacent pair. Amber is lighter than a categorical slot may be and clears
 * only 1.79:1 on the light surface, which is why every band also states its
 * availability in text and every segment names its counts on hover — colour
 * is never the only channel here.
 */
const STATUS_COLORS = {
    up: '#0ca30c',
    partial: '#fab219',
    down: '#d03b3b',
} as const;

type SlotState = keyof typeof STATUS_COLORS | 'unknown';

/**
 * The most segments a band draws before neighbouring slots are folded
 * together.
 *
 * A custom range can span 744 hourly slots, which at any real card width is
 * under a pixel each — too thin to read and far too thin to hover. Folding
 * keeps every period on a grid that stays clickable; the fold is by sum, so a
 * folded segment reports the checks and failures of the whole span it covers.
 */
const MAX_SEGMENTS = 120;

export type UptimeSeriesProject = {
    id: number;
    name: string;
    slug: string;
    status: string;
    uptime: number;
    checks: number;
    down: number;
    avg_response_time: number | null;
};

export type UptimeSeriesPoint = {
    minute: string;
} & Record<string, string | number | null | undefined>;

export type UptimeSeries = {
    projects: UptimeSeriesProject[];
    series: UptimeSeriesPoint[];
    omitted: number;
};

type Segment = {
    label: string;
    checks: number;
    failed: number;
    state: SlotState;
};

function formatUptime(value: number): string {
    return `${Number.isInteger(value) ? value : value.toFixed(2)}%`;
}

/**
 * How a slot went, from the share of its checks that failed.
 *
 * A slot with no checks is `unknown` rather than healthy — an application
 * that was not being watched has not earned a green segment.
 */
function stateFor(checks: number, failed: number): SlotState {
    if (checks === 0) {
        return 'unknown';
    }

    if (failed === 0) {
        return 'up';
    }

    return failed >= checks ? 'down' : 'partial';
}

/**
 * The band for one application: one segment per slot, folded onto a grid no
 * finer than {@see MAX_SEGMENTS}.
 */
function segmentsFor(
    series: UptimeSeriesPoint[],
    projectId: number,
): Segment[] {
    const stride = Math.ceil(series.length / MAX_SEGMENTS) || 1;
    const segments: Segment[] = [];

    for (let index = 0; index < series.length; index += stride) {
        const span = series.slice(index, index + stride);

        const checks = span.reduce(
            (total, slot) => total + Number(slot[`checks_${projectId}`] ?? 0),
            0,
        );
        const failed = span.reduce(
            (total, slot) => total + Number(slot[`failed_${projectId}`] ?? 0),
            0,
        );

        const first = span[0]?.minute ?? '';
        const last = span[span.length - 1]?.minute ?? first;

        segments.push({
            label: first === last ? first : `${first} – ${last}`,
            checks,
            failed,
            state: stateFor(checks, failed),
        });
    }

    return segments;
}

/**
 * Availability over the selected period, as a timeline per application.
 *
 * Every application gets its own row, so identity is carried by the name
 * beside the band rather than by a colour inside a shared stack, and colour is
 * left to say how each slot went. A healthy period reads as a full green bar
 * instead of the empty plot that charting failures alone produced, and a gap
 * in the monitoring is visibly a gap rather than a clean run.
 */
export function UptimeChart({
    series,
    period,
}: {
    series: UptimeSeries;
    period: string;
}) {
    const [hovered, setHovered] = useState<{
        projectId: number;
        index: number;
    } | null>(null);

    const bands = useMemo(
        () =>
            series.projects.map((project) => ({
                project,
                segments: segmentsFor(series.series, project.id),
            })),
        [series.projects, series.series],
    );

    if (series.projects.length === 0) {
        return (
            <div className="flex h-[280px] flex-col items-center justify-center gap-3 text-center">
                <Globe className="size-10 text-muted-foreground/20" />
                <div className="text-sm font-black tracking-tight text-foreground uppercase">
                    No availability recorded
                </div>
                <p className="max-w-[320px] text-[10px] font-black tracking-widest text-muted-foreground uppercase opacity-50">
                    Enable uptime monitoring on an application to chart it here
                </p>
            </div>
        );
    }

    const firstLabel = series.series[0]?.minute ?? '';
    const lastLabel = series.series[series.series.length - 1]?.minute ?? '';

    return (
        <div className="space-y-6">
            <div className="space-y-4">
                {bands.map(({ project, segments }) => {
                    const active =
                        hovered?.projectId === project.id
                            ? segments[hovered.index]
                            : null;

                    return (
                        <div key={project.id} className="space-y-1.5">
                            <div className="flex items-baseline justify-between gap-3">
                                <span className="truncate text-[11px] font-black tracking-widest text-foreground uppercase">
                                    {project.name}
                                </span>

                                <span className="flex shrink-0 items-baseline gap-2">
                                    {project.down > 0 && (
                                        <span className="text-[10px] font-medium text-muted-foreground/60">
                                            {project.down} of {project.checks}{' '}
                                            failed
                                        </span>
                                    )}
                                    <span
                                        className={cn(
                                            'text-[11px] font-black tracking-tight tabular-nums',
                                            project.uptime >= 99.9
                                                ? 'text-emerald-500'
                                                : project.uptime >= 99
                                                  ? 'text-orange-500'
                                                  : 'text-red-500',
                                        )}
                                    >
                                        {formatUptime(project.uptime)}
                                    </span>
                                </span>
                            </div>

                            <div className="relative">
                                <div
                                    className="flex h-9 gap-px overflow-hidden rounded-[3px]"
                                    role="img"
                                    aria-label={`${project.name}: ${formatUptime(project.uptime)} available over the last ${period}, ${project.down} of ${project.checks} checks failed`}
                                    onMouseLeave={() => setHovered(null)}
                                >
                                    {segments.map((segment, index) => (
                                        <div
                                            key={index}
                                            onMouseEnter={() =>
                                                setHovered({
                                                    projectId: project.id,
                                                    index,
                                                })
                                            }
                                            className={cn(
                                                'h-full min-w-0 flex-1 transition-opacity',
                                                segment.state === 'unknown' &&
                                                    'bg-muted-foreground/15',
                                                hovered?.projectId ===
                                                    project.id &&
                                                    hovered.index !== index &&
                                                    'opacity-50',
                                            )}
                                            style={
                                                segment.state === 'unknown'
                                                    ? undefined
                                                    : {
                                                          backgroundColor:
                                                              STATUS_COLORS[
                                                                  segment.state
                                                              ],
                                                      }
                                            }
                                        />
                                    ))}
                                </div>

                                {active && (
                                    <div className="pointer-events-none absolute -top-2 left-1/2 z-10 -translate-x-1/2 -translate-y-full rounded-lg border border-border bg-background/95 px-2.5 py-1.5 shadow-xl backdrop-blur-sm">
                                        <div className="text-[9px] font-bold tracking-tight text-muted-foreground uppercase">
                                            {active.label}
                                        </div>
                                        <div className="mt-0.5 text-[10px] font-bold whitespace-nowrap text-foreground">
                                            {active.checks === 0
                                                ? 'Not monitored'
                                                : active.failed === 0
                                                  ? `All ${active.checks} checks passed`
                                                  : `${active.failed} of ${active.checks} checks failed`}
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    );
                })}
            </div>

            <div className="flex items-center justify-between gap-4 text-[10px] font-black tracking-widest text-muted-foreground uppercase opacity-50">
                <span>{firstLabel}</span>
                <span>{lastLabel}</span>
            </div>

            <div className="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-border/50 pt-4">
                {(
                    [
                        ['up', 'All passed'],
                        ['partial', 'Some failed'],
                        ['down', 'All failed'],
                    ] as const
                ).map(([state, label]) => (
                    <span key={state} className="flex items-center gap-1.5">
                        <span
                            className="size-2 shrink-0 rounded-[2px]"
                            style={{ backgroundColor: STATUS_COLORS[state] }}
                        />
                        <span className="text-[10px] font-black tracking-widest text-muted-foreground uppercase">
                            {label}
                        </span>
                    </span>
                ))}
                <span className="flex items-center gap-1.5">
                    <span className="size-2 shrink-0 rounded-[2px] bg-muted-foreground/15" />
                    <span className="text-[10px] font-black tracking-widest text-muted-foreground uppercase">
                        Not monitored
                    </span>
                </span>

                <span className="ml-auto text-[10px] font-black tracking-widest text-muted-foreground uppercase opacity-70">
                    {series.omitted > 0
                        ? `Least available ${series.projects.length} of ${series.projects.length + series.omitted} · last ${period}`
                        : `Last ${period}`}
                </span>
            </div>
        </div>
    );
}
