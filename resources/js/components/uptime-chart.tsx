import { CheckCircle2, Globe } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { cn } from '@/lib/utils';

/**
 * Categorical series colours, stepped for the dark surface the app renders on
 * and assigned in this fixed order: the ordering is what keeps stacked
 * segments apart for colour-vision deficiencies, so an application takes the
 * next free slot rather than a hue of its own. There are eight, which is why
 * the server caps the chart at eight applications.
 */
const SERIES_COLORS = [
    '#3987e5',
    '#d95926',
    '#199e70',
    '#c98500',
    '#d55181',
    '#008300',
    '#9085e9',
    '#e66767',
];

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

function formatUptime(value: number): string {
    return `${Number.isInteger(value) ? value : value.toFixed(2)}%`;
}

/**
 * Failed uptime checks over the selected period, stacked per application.
 *
 * Availability charted directly is a flat line at 100% that nobody reads, so
 * the plot draws the exception instead: a bar rises only where a check
 * failed, and its segments say which applications were behind it. The legend
 * underneath carries the availability each application actually reached, and
 * doubles as the control for the plot — clicking an application takes its
 * segments out of the stack.
 */
export function UptimeChart({
    series,
    period,
}: {
    series: UptimeSeries;
    period: string;
}) {
    const [hidden, setHidden] = useState<number[]>([]);

    const colorFor = useMemo(() => {
        const colors = new Map<number, string>();

        series.projects.forEach((project, index) => {
            colors.set(project.id, SERIES_COLORS[index % SERIES_COLORS.length]);
        });

        return colors;
    }, [series.projects]);

    const visible = series.projects.filter(
        (project) => !hidden.includes(project.id),
    );

    // Every slot is on the x-axis whether or not anything failed in it, so an
    // empty plot means "nothing went down", not "nothing was measured".
    const hasFailures = useMemo(
        () =>
            series.series.some((slot) =>
                visible.some(
                    (project) => slot[`failed_${project.id}`] !== undefined,
                ),
            ),
        [series.series, visible],
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

    const toggle = (id: number) => {
        setHidden((current) =>
            current.includes(id)
                ? current.filter((hiddenId) => hiddenId !== id)
                : [...current, id],
        );
    };

    return (
        <div className="space-y-6">
            <div className="relative h-[280px] w-full">
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart
                        data={series.series}
                        margin={{ top: 8, right: 8, bottom: 0, left: -20 }}
                    >
                        <CartesianGrid
                            vertical={false}
                            strokeDasharray="3 3"
                            className="stroke-border/40"
                        />
                        <XAxis
                            dataKey="minute"
                            tickLine={false}
                            axisLine={false}
                            minTickGap={48}
                            tick={{ fontSize: 9, fill: 'currentColor' }}
                            className="text-muted-foreground/60"
                        />
                        <YAxis
                            allowDecimals={false}
                            tickLine={false}
                            axisLine={false}
                            width={44}
                            tick={{ fontSize: 9, fill: 'currentColor' }}
                            className="text-muted-foreground/60"
                        />
                        <Tooltip
                            cursor={{ fill: 'currentColor', fillOpacity: 0.06 }}
                            content={({ active, payload, label }) => {
                                if (!active) {
                                    return null;
                                }

                                // A slot where nothing failed has no bar to
                                // hand over its row, so it is looked up by
                                // its label instead of skipped.
                                const slot =
                                    payload?.[0]?.payload ??
                                    series.series.find(
                                        (point) => point.minute === label,
                                    ) ??
                                    {};
                                const rows = visible
                                    .map((project) => ({
                                        project,
                                        failed: slot[`failed_${project.id}`],
                                        checks: slot[`checks_${project.id}`],
                                    }))
                                    .filter((row) => row.failed !== undefined)
                                    .sort(
                                        (a, b) =>
                                            (b.failed as number) -
                                            (a.failed as number),
                                    );

                                return (
                                    <div className="rounded-lg border border-border bg-background/95 p-2 shadow-xl backdrop-blur-sm">
                                        <div className="mb-1.5 border-b border-border/50 pb-1 text-[9px] font-bold tracking-tight text-muted-foreground uppercase">
                                            {label}
                                        </div>
                                        {rows.length === 0 ? (
                                            <div className="text-[10px] font-bold text-emerald-500 uppercase">
                                                All checks passed
                                            </div>
                                        ) : (
                                            <div className="grid gap-1">
                                                {rows.map((row) => (
                                                    <div
                                                        key={row.project.id}
                                                        className="flex items-center gap-2"
                                                    >
                                                        <div
                                                            className="size-2 shrink-0 rounded-full"
                                                            style={{
                                                                backgroundColor:
                                                                    colorFor.get(
                                                                        row
                                                                            .project
                                                                            .id,
                                                                    ),
                                                            }}
                                                        />
                                                        <span className="max-w-[140px] truncate text-[10px] font-medium text-muted-foreground uppercase">
                                                            {row.project.name}
                                                        </span>
                                                        <span className="ml-auto text-[10px] font-bold text-foreground">
                                                            {
                                                                row.failed as number
                                                            }{' '}
                                                            of{' '}
                                                            {
                                                                row.checks as number
                                                            }{' '}
                                                            failed
                                                        </span>
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                );
                            }}
                        />
                        {series.projects.map((project) => (
                            <Bar
                                key={project.id}
                                dataKey={`failed_${project.id}`}
                                name={project.name}
                                fill={colorFor.get(project.id)}
                                stackId="failed"
                                radius={[2, 2, 0, 0]}
                                isAnimationActive={false}
                                hide={hidden.includes(project.id)}
                            />
                        ))}
                    </BarChart>
                </ResponsiveContainer>

                {!hasFailures && (
                    <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-2">
                        <CheckCircle2 className="size-8 text-emerald-500/40" />
                        <div className="text-[10px] font-black tracking-widest text-muted-foreground uppercase opacity-60">
                            No failed checks in the last {period}
                        </div>
                    </div>
                )}
            </div>

            <div className="flex flex-wrap gap-2">
                {series.projects.map((project) => {
                    const isHidden = hidden.includes(project.id);

                    return (
                        <button
                            key={project.id}
                            type="button"
                            onClick={() => toggle(project.id)}
                            aria-pressed={!isHidden}
                            className={cn(
                                'group flex items-center gap-2 rounded-lg border border-border bg-muted/40 py-1.5 pr-3 pl-2.5 transition-all hover:bg-muted',
                                isHidden && 'opacity-40',
                            )}
                        >
                            <span
                                className="size-2 shrink-0 rounded-full"
                                style={{
                                    backgroundColor: isHidden
                                        ? 'transparent'
                                        : colorFor.get(project.id),
                                    boxShadow: isHidden
                                        ? `inset 0 0 0 1px ${colorFor.get(project.id)}`
                                        : undefined,
                                }}
                            />
                            <span className="max-w-[160px] truncate text-[10px] font-black tracking-widest text-foreground uppercase">
                                {project.name}
                            </span>
                            <span
                                className={cn(
                                    'text-[10px] font-black tracking-tight',
                                    project.uptime >= 99.9
                                        ? 'text-emerald-500'
                                        : project.uptime >= 99
                                          ? 'text-orange-500'
                                          : 'text-red-500',
                                )}
                            >
                                {formatUptime(project.uptime)}
                            </span>
                            {project.down > 0 && (
                                <span className="text-[10px] font-medium text-muted-foreground/60">
                                    {project.down} failed
                                </span>
                            )}
                        </button>
                    );
                })}
            </div>

            <div className="text-[10px] font-black tracking-widest text-muted-foreground uppercase opacity-50">
                {series.omitted > 0
                    ? `Failed checks · least available ${series.projects.length} of ${series.projects.length + series.omitted} monitored applications · last ${period}`
                    : `Failed checks per application · last ${period}`}
            </div>
        </div>
    );
}
