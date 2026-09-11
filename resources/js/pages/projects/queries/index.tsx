import { Head, Link, usePage } from '@inertiajs/react';
import { Database, ArrowUpRight, FileCode } from 'lucide-react';
import {
    BarChart,
    Bar,
    ResponsiveContainer,
    AreaChart,
    Area,
    Tooltip,
    XAxis,
} from 'recharts';
import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Card, CardContent } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useLiveReload } from '@/hooks/use-live-reload';
import AppLayout from '@/layouts/app-layout';
import { monitoringQuery } from '@/lib/monitoring-query';
import { formatMicroSeconds, formatCompactNumber } from '@/lib/utils';
import { show as showQuery } from '@/routes/queries';

export default function QueriesIndex({
    queries,
    timeSeries = [],
    overview,
    period,
    from,
    to,
}: {
    queries: any;
    timeSeries: any;
    overview: any;
    period?: string | null;
    from?: string | null;
    to?: string | null;
}) {
    const { props }: any = usePage();
    const teamSlug = props.current_team?.slug || props.currentTeam?.slug;
    const currentProject = props.current_project || props.currentProject;
    const projectSlug =
        props.current_project?.slug || props.currentProject?.slug;

    useLiveReload(currentProject?.id);

    const data = queries.data || [];
    const queryDetailsHref = (hash: string | number) =>
        showQuery.url(
            {
                current_team: teamSlug,
                project: projectSlug,
                hash,
            },
            monitoringQuery({ period, from, to }),
        );

    return (
        <>
            <Head title="Database Queries" />

            <div className="mb-8 space-y-4"></div>

            {/* Stats Cards */}
            <div className="mb-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
                <Card className="overflow-hidden border-border bg-card shadow-2xl">
                    <CardContent className="p-6">
                        <div className="mb-6 flex items-start justify-between">
                            <div>
                                <div className="text-3xl font-bold text-foreground">
                                    {formatCompactNumber(overview.total)}
                                </div>
                            </div>
                        </div>
                        <div className="h-[120px] w-full">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={timeSeries}>
                                    <XAxis dataKey="minute" hide />
                                    <Tooltip
                                        content={({ active, payload }) => {
                                            if (
                                                active &&
                                                payload &&
                                                payload.length
                                            ) {
                                                return (
                                                    <div className="rounded-lg border border-border bg-background/95 p-2 shadow-xl backdrop-blur-sm">
                                                        <div className="mb-1.5 border-b border-border/50 pb-1 text-[9px] font-bold tracking-tight text-muted-foreground uppercase">
                                                            {
                                                                payload[0]
                                                                    .payload
                                                                    .minute
                                                            }
                                                        </div>
                                                        <div className="flex items-center gap-2">
                                                            <div className="h-2 w-2 rounded-full bg-white/40" />
                                                            <span className="text-[10px] font-medium text-muted-foreground uppercase">
                                                                Queries:
                                                            </span>
                                                            <span className="text-[10px] font-bold text-foreground">
                                                                {formatCompactNumber(
                                                                    payload[0]
                                                                        .value,
                                                                )}
                                                            </span>
                                                        </div>
                                                    </div>
                                                );
                                            }

                                            return null;
                                        }}
                                    />
                                    <Bar
                                        dataKey="total"
                                        name="Total Queries"
                                        fill="rgba(255,255,255,0.4)"
                                        radius={[2, 2, 0, 0]}
                                    />
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    </CardContent>
                </Card>

                <Card className="overflow-hidden border-border bg-card shadow-2xl">
                    <CardContent className="p-6">
                        <div className="mb-6 flex items-start justify-between">
                            <div>
                                <div className="text-3xl font-bold text-foreground">
                                    {formatMicroSeconds(overview.avg_duration)}
                                </div>
                            </div>
                        </div>
                        <div className="h-[120px] w-full">
                            <ResponsiveContainer width="100%" height="100%">
                                <AreaChart data={timeSeries}>
                                    <Area
                                        type="monotone"
                                        dataKey="avg_duration"
                                        stroke="#f97316"
                                        fill="#f97316"
                                        fillOpacity={0.1}
                                        strokeWidth={2}
                                    />
                                </AreaChart>
                            </ResponsiveContainer>
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* Table Section */}
            <div className="space-y-4">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2 font-bold text-foreground">
                        <Database className="h-4 w-4 text-muted-foreground" />
                        <span>
                            {formatCompactNumber(queries.total || 0)} Queries
                        </span>
                    </div>
                </div>

                <div className="overflow-hidden rounded-lg border border-border bg-card">
                    {data.length > 0 ? (
                        <>
                            <Table>
                                <TableHeader className="bg-muted/30">
                                    <TableRow className="border-border text-[10px] font-bold text-muted-foreground uppercase hover:bg-transparent">
                                        <TableHead className="pl-6">
                                            Query
                                        </TableHead>
                                        <TableHead className="text-center">
                                            Connection
                                        </TableHead>
                                        <TableHead className="text-center">
                                            Calls
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Total
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Avg
                                        </TableHead>
                                        <TableHead className="pr-6 text-right">
                                            P95
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {data.map((q: any, index: number) => (
                                        <TableRow
                                            key={index}
                                            className="group border-border transition-colors hover:bg-muted/30"
                                        >
                                            <TableCell className="max-w-[500px] pl-6">
                                                <div className="flex items-start gap-2">
                                                    <FileCode className="mt-1 h-3 w-3 shrink-0 text-muted-foreground/50" />
                                                    <span className="line-clamp-2 cursor-default font-mono text-xs text-blue-400 transition-all hover:line-clamp-none">
                                                        {q.sql_query}
                                                    </span>
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-center font-mono text-xs text-muted-foreground uppercase">
                                                {q.db_connection}
                                            </TableCell>
                                            <TableCell className="text-center font-mono text-xs text-foreground/60">
                                                {formatCompactNumber(
                                                    q.total_calls || 0,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right font-mono text-xs text-foreground/90">
                                                {formatMicroSeconds(
                                                    q.total_duration,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right font-mono text-xs text-foreground/90">
                                                {formatMicroSeconds(
                                                    q.avg_duration,
                                                )}
                                            </TableCell>
                                            <TableCell className="pr-6 text-right font-mono text-xs font-bold text-foreground">
                                                <div className="flex items-center justify-end gap-2">
                                                    <span>
                                                        {formatMicroSeconds(
                                                            q.p95_duration,
                                                        )}
                                                    </span>
                                                    <Link
                                                        href={queryDetailsHref(
                                                            q.hash,
                                                        )}
                                                    >
                                                        <div className="rounded border border-border bg-muted p-1 transition-all group-hover:border-border">
                                                            <ArrowUpRight className="h-3 w-3" />
                                                        </div>
                                                    </Link>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                            <Pagination links={queries.links} meta={queries} />
                        </>
                    ) : (
                        <div className="p-12">
                            <EmptyState
                                title="No Queries Found"
                                description="We haven't detected any database queries for the selected period. Make sure your database monitoring is enabled."
                                icon={Database}
                            />
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

QueriesIndex.layout = (page: any) => (
    <AppLayout
        children={page}
        breadcrumbs={[{ title: 'Queries', href: '#' }]}
    />
);
