import { Globe } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    CartesianGrid,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { cn } from '@/lib/utils';

/**
 * Categorical series colours, stepped for the dark surface the app renders on
 * and assigned in this fixed order: the ordering is what keeps adjacent lines
 * apart for colour-vision deficiencies, so a line takes the next free slot
 * rather than a hue of its own. There are eight, which is why the server caps
 * the chart at eight applications.
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
 * Availability per application over the selected period.
 *
 * Every line is one application, and the legend below the plot doubles as the
 * control for it: clicking an application takes its line off the chart, so a
 * single outage can be read on its own without the healthy lines stacked on
 * top of it at 100%.
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

    const visible = series.projects.filter(
        (project) => !hidden.includes(project.id),
    );

    return (
        <div className="space-y-6">
            <div className="h-[280px] w-full">
                <ResponsiveContainer width="100%" height="100%">
                    <LineChart
                        data={series.series}
                        margin={{ top: 8, right: 8, bottom: 0, left: -16 }}
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
                            domain={[0, 100]}
                            ticks={[0, 50, 100]}
                            tickLine={false}
                            axisLine={false}
                            width={48}
                            tickFormatter={(value: number) => `${value}%`}
                            tick={{ fontSize: 9, fill: 'currentColor' }}
                            className="text-muted-foreground/60"
                        />
                        <Tooltip
                            cursor={{
                                stroke: 'currentColor',
                                strokeOpacity: 0.2,
                            }}
                            content={({ active, payload, label }) => {
                                if (!active || !payload?.length) {
                                    return null;
                                }

                                const slot = payload[0].payload ?? {};
                                const rows = visible
                                    .map((project) => ({
                                        project,
                                        uptime: slot[`uptime_${project.id}`],
                                        response:
                                            slot[`response_${project.id}`],
                                    }))
                                    .filter(
                                        (row) =>
                                            row.uptime !== undefined &&
                                            row.uptime !== null,
                                    )
                                    .sort(
                                        (a, b) =>
                                            (a.uptime as number) -
                                            (b.uptime as number),
                                    );

                                if (rows.length === 0) {
                                    return null;
                                }

                                return (
                                    <div className="rounded-lg border border-border bg-background/95 p-2 shadow-xl backdrop-blur-sm">
                                        <div className="mb-1.5 border-b border-border/50 pb-1 text-[9px] font-bold tracking-tight text-muted-foreground uppercase">
                                            {label}
                                        </div>
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
                                                                    row.project
                                                                        .id,
                                                                ),
                                                        }}
                                                    />
                                                    <span className="max-w-[140px] truncate text-[10px] font-medium text-muted-foreground uppercase">
                                                        {row.project.name}
                                                    </span>
                                                    <span className="ml-auto text-[10px] font-bold text-foreground">
                                                        {formatUptime(
                                                            row.uptime as number,
                                                        )}
                                                    </span>
                                                    {row.response !== null &&
                                                        row.response !==
                                                            undefined && (
                                                            <span className="text-[10px] font-medium text-muted-foreground/60">
                                                                {row.response}ms
                                                            </span>
                                                        )}
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                );
                            }}
                        />
                        {series.projects.map((project) => (
                            <Line
                                key={project.id}
                                type="monotone"
                                dataKey={`uptime_${project.id}`}
                                name={project.name}
                                stroke={colorFor.get(project.id)}
                                strokeWidth={2}
                                dot={false}
                                activeDot={{ r: 4, strokeWidth: 0 }}
                                isAnimationActive={false}
                                hide={hidden.includes(project.id)}
                                // An application checked every few minutes
                                // leaves most slots of the hour view empty;
                                // without this its line would have no segment
                                // to draw at all.
                                connectNulls
                            />
                        ))}
                    </LineChart>
                </ResponsiveContainer>
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
                            {project.avg_response_time !== null && (
                                <span className="text-[10px] font-medium text-muted-foreground/60">
                                    {project.avg_response_time}ms
                                </span>
                            )}
                        </button>
                    );
                })}
            </div>

            <div className="text-[10px] font-black tracking-widest text-muted-foreground uppercase opacity-50">
                {series.omitted > 0
                    ? `Least available ${series.projects.length} of ${series.projects.length + series.omitted} monitored applications · last ${period}`
                    : `Availability per application · last ${period}`}
            </div>
        </div>
    );
}
