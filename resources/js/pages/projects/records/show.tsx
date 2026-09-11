import { Head, usePage } from '@inertiajs/react';
import {
    Activity,
    Globe,
    Database,
    Mail,
    Bell,
    Layers,
    ChevronDown,
    ChevronRight,
} from 'lucide-react';
import { useState } from 'react';
import { vscDarkPlus } from 'react-syntax-highlighter/dist/esm/styles/prism';
import { CodeBlock } from '@/components/code-block';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import AppLayout from '@/layouts/app-layout';

export default function RecordShow({
    record,
    relatedRecords = [],
    highlight_record_id,
}: {
    record: any;
    relatedRecords?: any[];
    highlight_record_id?: number;
}) {
    usePage();
    const payload = record.payload || {};
    const [expandedHeaders, setExpandedHeaders] = useState(false);
    const [expandedPayload, setExpandedPayload] = useState(false);

    const getStatusColor = (status: number) => {
        if (status >= 500) {
            return 'text-red-500 border-red-500/20 bg-red-500/5';
        }

        if (status >= 400) {
            return 'text-orange-500 border-orange-500/20 bg-orange-500/5';
        }

        return 'text-emerald-500 border-emerald-500/20 bg-emerald-500/5';
    };

    const queries = relatedRecords.filter((r) => r.type === 'query');

    const countOf = (value: unknown) =>
        typeof value === 'number'
            ? value
            : Array.isArray(value)
              ? value.length
              : 0;

    const events = [
        {
            label: 'QUERIES',
            icon: Database,
            count: countOf(payload.queries),
            duration:
                queries.reduce((acc, r) => acc + (r.payload.duration || 0), 0) /
                1000,
        },
        {
            label: 'MAIL',
            icon: Mail,
            count: countOf(payload.mail),
            duration: 0,
        },
        {
            label: 'CACHE',
            icon: Layers,
            count: countOf(payload.cache_events),
            duration: 0,
        },
        {
            label: 'OUTGOING REQUESTS',
            icon: Globe,
            count: countOf(payload.outgoing_requests),
            duration: 0,
        },
        {
            label: 'NOTIFICATIONS',
            icon: Bell,
            count: countOf(payload.notifications),
            duration: 0,
        },
        {
            label: 'QUEUED JOBS',
            icon: Activity,
            count: countOf(payload.jobs_queued),
            duration: 0,
        },
    ];

    const totalEvents = events.reduce((acc, event) => acc + event.count, 0);

    const subLabel = (sub: any) => {
        const p = sub.payload || {};

        switch (sub.type) {
            case 'query':
                return p.sql;
            case 'outgoing-request':
                return p.url || p.host;
            case 'mail':
                return p.subject || p.class;
            case 'notification':
                return p.class;
            case 'cache-event':
                return `${p.type || 'cache'} ${p.key || ''}`.trim();
            default:
                return p.message || 'Event';
        }
    };

    const isRequest = record.type === 'request';
    const isJob = ['job-attempt', 'queued-job'].includes(record.type);
    const isCommand = ['command', 'scheduled-task'].includes(record.type);
    const isQuery = record.type === 'query';

    const title = isJob
        ? payload.name || payload.job || 'Job Execution'
        : isCommand
          ? payload.command || 'Command Execution'
          : isQuery
            ? 'Database Query'
            : payload.route_path || record.type.toUpperCase();

    const subTitle = isRequest
        ? payload.url || `https://${payload.server}${payload.route_path}`
        : isJob
          ? `${payload.connection || 'default'} @ ${payload.queue || 'default'}`
          : isCommand
            ? payload.arguments || 'No arguments'
            : '';

    return (
        <>
            <Head title={`${record.type.toUpperCase()} Details - ${title}`} />

            <div className="mb-8 space-y-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="text-2xl font-bold tracking-tight text-foreground">
                            {title}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {subTitle}
                        </p>
                    </div>

                    <Badge
                        variant="outline"
                        className="rounded border-border bg-muted px-3 py-1 text-xs font-bold text-foreground uppercase"
                    >
                        {payload.method ||
                            record.type.replace('-', ' ').toUpperCase()}
                    </Badge>
                </div>
            </div>

            <div className="max-w-7xl space-y-6">
                {/* Main Info Card */}
                <Card className="border-border bg-card p-8 shadow-2xl">
                    <div className="space-y-6">
                        <div className="flex items-center gap-3 font-mono text-sm text-emerald-400">
                            <Globe className="h-4 w-4" />
                            <span className="break-all">
                                {payload.url ||
                                    `https://${payload.server}${payload.route_path}`}
                            </span>
                        </div>

                        <div className="grid grid-cols-1 gap-x-12 gap-y-4 md:grid-cols-2">
                            <div className="flex items-center justify-between border-b border-border py-2">
                                <span className="text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                    Date
                                </span>
                                <span className="font-mono text-sm text-foreground/90">
                                    {new Date(
                                        record.created_at,
                                    ).toLocaleString()}{' '}
                                    UTC
                                </span>
                            </div>

                            {isRequest && (
                                <>
                                    <div className="flex items-center justify-between border-b border-border py-2">
                                        <span className="text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                            Status Code
                                        </span>
                                        <Badge
                                            className={getStatusColor(
                                                payload.status_code,
                                            )}
                                        >
                                            {payload.status_code}
                                        </Badge>
                                    </div>
                                    <div className="flex items-center justify-between border-b border-border py-2">
                                        <span className="text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                            Response Size
                                        </span>
                                        <span className="font-mono text-sm text-foreground/90">
                                            {(
                                                (payload.response_size || 0) /
                                                1024
                                            ).toFixed(2)}{' '}
                                            KB
                                        </span>
                                    </div>
                                </>
                            )}

                            {isJob && (
                                <>
                                    <div className="flex items-center justify-between border-b border-border py-2">
                                        <span className="text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                            Status
                                        </span>
                                        <Badge
                                            className={
                                                payload.status === 'processed'
                                                    ? 'bg-emerald-500/10 text-emerald-500'
                                                    : 'bg-red-500/10 text-red-500'
                                            }
                                        >
                                            {payload.status || 'STARTED'}
                                        </Badge>
                                    </div>
                                    <div className="flex items-center justify-between border-b border-border py-2">
                                        <span className="text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                            Tries
                                        </span>
                                        <span className="font-mono text-sm text-foreground/90">
                                            {payload.tries || 1}
                                        </span>
                                    </div>
                                </>
                            )}

                            {isCommand && (
                                <div className="flex items-center justify-between border-b border-border py-2">
                                    <span className="text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                        Exit Code
                                    </span>
                                    <Badge
                                        className={
                                            payload.exit_code === 0
                                                ? 'bg-emerald-500/10 text-emerald-500'
                                                : 'bg-red-500/10 text-red-500'
                                        }
                                    >
                                        {payload.exit_code ?? 'N/A'}
                                    </Badge>
                                </div>
                            )}

                            <div className="flex items-center justify-between border-b border-border py-2">
                                <span className="text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                    Server
                                </span>
                                <span className="font-mono text-sm text-foreground/90">
                                    {payload.server || 'Unknown'}
                                </span>
                            </div>

                            <div className="flex items-center justify-between py-2">
                                <span className="text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                    Peak Memory
                                </span>
                                <span className="font-mono text-sm text-foreground/90">
                                    {(
                                        (payload.peak_memory_usage || 0) /
                                        1024 /
                                        1024
                                    ).toFixed(2)}{' '}
                                    MB
                                </span>
                            </div>
                        </div>

                        <div className="pt-6">
                            <h3 className="mb-4 text-xs font-bold text-foreground uppercase">
                                User
                            </h3>
                            <div className="flex items-center justify-between border-b border-border py-2">
                                <span className="text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                    IP
                                </span>
                                <span className="font-mono text-sm text-foreground/90">
                                    {payload.ip || '127.0.0.1'}
                                </span>
                            </div>
                        </div>

                        <div className="pt-6">
                            <div className="mb-4 flex items-center justify-between">
                                <h3 className="text-xs font-bold text-foreground uppercase">
                                    Events
                                </h3>
                                <div className="flex gap-4 rounded bg-muted px-2 py-1 text-[9px] font-bold uppercase">
                                    <span>
                                        Events{' '}
                                        <span className="ml-1 text-foreground">
                                            {totalEvents}
                                        </span>
                                    </span>
                                    <span>
                                        Duration{' '}
                                        <span className="ml-1 text-foreground">
                                            {(
                                                (payload.duration || 0) / 1000
                                            ).toFixed(2)}
                                            ms
                                        </span>
                                    </span>
                                </div>
                            </div>
                            <div className="grid grid-cols-1 gap-x-12 md:grid-cols-2">
                                {events.map((event, i) => (
                                    <div
                                        key={i}
                                        className="flex items-center justify-between border-b border-border py-2"
                                    >
                                        <span className="text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                            {event.label}
                                        </span>
                                        <div className="flex items-center gap-2">
                                            <span className="font-mono text-sm text-foreground/90">
                                                {event.count} Events
                                            </span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                </Card>

                {/* Headers Card */}
                <Card className="border-border bg-card shadow-2xl">
                    <Collapsible
                        open={expandedHeaders}
                        onOpenChange={setExpandedHeaders}
                    >
                        <CollapsibleTrigger asChild>
                            <div className="flex cursor-pointer items-center justify-between p-4 transition-colors hover:bg-muted/30">
                                <h3 className="text-xs font-bold text-foreground uppercase">
                                    Headers
                                </h3>
                                <div className="flex h-5 w-5 items-center justify-center rounded bg-muted">
                                    {expandedHeaders ? (
                                        <ChevronDown className="h-3 w-3" />
                                    ) : (
                                        <ChevronRight className="h-3 w-3" />
                                    )}
                                </div>
                            </div>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <div className="border-t border-border p-6 text-xs">
                                {(() => {
                                    let headersObj = payload.headers || {};

                                    if (typeof headersObj === 'string') {
                                        try {
                                            headersObj = JSON.parse(headersObj);
                                        } catch {
                                            // ignore
                                        }
                                    }

                                    return (
                                        <CodeBlock
                                            language="json"
                                            style={vscDarkPlus}
                                            customStyle={{
                                                margin: 0,
                                                borderRadius: '0.5rem',
                                                border: '1px solid hsl(var(--border))',
                                            }}
                                            wrapLines={true}
                                            wrapLongLines={true}
                                        >
                                            {JSON.stringify(
                                                headersObj,
                                                null,
                                                4,
                                            )}
                                        </CodeBlock>
                                    );
                                })()}
                            </div>
                        </CollapsibleContent>
                    </Collapsible>
                </Card>

                {/* Request Payload Card */}
                {(() => {
                    let body = payload.payload;

                    if (typeof body === 'string') {
                        try {
                            body = JSON.parse(body);
                        } catch {
                            // Leave non-JSON bodies as their raw string.
                        }
                    }

                    const isEmpty =
                        body === null ||
                        body === undefined ||
                        body === '' ||
                        (typeof body === 'object' &&
                            Object.keys(body).length === 0);

                    if (isEmpty) {
                        return null;
                    }

                    return (
                        <Card className="border-border bg-card shadow-2xl">
                            <Collapsible
                                open={expandedPayload}
                                onOpenChange={setExpandedPayload}
                            >
                                <CollapsibleTrigger asChild>
                                    <div className="flex cursor-pointer items-center justify-between p-4 transition-colors hover:bg-muted/30">
                                        <h3 className="text-xs font-bold text-foreground uppercase">
                                            Request Payload
                                        </h3>
                                        <div className="flex h-5 w-5 items-center justify-center rounded bg-muted">
                                            {expandedPayload ? (
                                                <ChevronDown className="h-3 w-3" />
                                            ) : (
                                                <ChevronRight className="h-3 w-3" />
                                            )}
                                        </div>
                                    </div>
                                </CollapsibleTrigger>
                                <CollapsibleContent>
                                    <div className="border-t border-border p-6 text-xs">
                                        <CodeBlock
                                            language="json"
                                            style={vscDarkPlus}
                                            customStyle={{
                                                margin: 0,
                                                borderRadius: '0.5rem',
                                                border: '1px solid hsl(var(--border))',
                                            }}
                                            wrapLines={true}
                                            wrapLongLines={true}
                                        >
                                            {typeof body === 'string'
                                                ? body
                                                : JSON.stringify(body, null, 4)}
                                        </CodeBlock>
                                    </div>
                                </CollapsibleContent>
                            </Collapsible>
                        </Card>
                    );
                })()}

                {/* Timeline Card */}
                <Card className="border-border bg-card p-8 shadow-2xl">
                    <div className="mb-8 flex items-center justify-between">
                        <h3 className="text-xs font-bold text-foreground uppercase">
                            Timeline
                        </h3>
                        <div className="flex gap-12 text-[10px] font-bold text-muted-foreground uppercase">
                            <span>0ms</span>
                            <span>
                                {((payload.duration || 0) / 1000).toFixed(0)}ms
                            </span>
                        </div>
                    </div>

                    <div className="space-y-4">
                        <div className="relative border-l border-border pl-4">
                            <div className="mb-2 flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <ChevronDown className="h-3 w-3 text-muted-foreground" />
                                    <span className="text-[10px] font-bold tracking-widest uppercase">
                                        Request {payload.route_path}
                                    </span>
                                </div>
                                <div className="flex h-5 items-center gap-2 rounded border border-emerald-500/30 bg-emerald-500/20 px-2">
                                    <span className="text-[9px] font-bold text-emerald-400 uppercase">
                                        {record.type.toUpperCase()}
                                    </span>
                                    {isRequest && (
                                        <Badge className="h-3 rounded-sm border-none bg-emerald-500 px-1 text-[9px]">
                                            {payload.status_code}
                                        </Badge>
                                    )}
                                    <span className="font-mono text-[10px] text-foreground">
                                        {(
                                            (payload.duration || 0) / 1000
                                        ).toFixed(2)}
                                        ms
                                    </span>
                                    <span className="font-mono text-[10px] text-muted-foreground">
                                        {title}
                                    </span>
                                </div>
                            </div>

                            {/* Sub-items (Dynamic from relatedRecords) */}
                            <div className="mt-4 ml-4 space-y-3">
                                {isRequest && (
                                    <>
                                        <div className="group flex items-center justify-between">
                                            <span className="text-[10px] font-bold tracking-tighter text-muted-foreground/60 uppercase">
                                                Bootstrap
                                            </span>
                                            <div className="flex h-6 w-[70%] items-center justify-between rounded border border-border bg-muted px-3">
                                                <span className="text-[10px] font-bold uppercase">
                                                    Bootstrap
                                                </span>
                                                <span className="font-mono text-[10px]">
                                                    {(
                                                        (payload.bootstrap ||
                                                            0) / 1000
                                                    ).toFixed(2)}
                                                    ms
                                                </span>
                                            </div>
                                        </div>

                                        {relatedRecords.map((sub, i) => (
                                            <div
                                                key={i}
                                                className={`group flex items-center justify-between ${highlight_record_id === sub.id ? 'rounded ring-1 ring-blue-500 ring-offset-2 ring-offset-[#111111]' : ''}`}
                                            >
                                                <div className="ml-4 flex items-center gap-2">
                                                    <div
                                                        className={`h-1.5 w-1.5 rounded-full border ${sub.type === 'query' ? 'border-blue-500/50' : 'border-red-500/50'}`}
                                                    ></div>
                                                    <span
                                                        className={`text-[10px] font-bold tracking-tighter uppercase ${sub.type === 'query' ? 'text-blue-500' : 'text-red-500'}`}
                                                    >
                                                        {sub.type}
                                                    </span>
                                                    <span className="line-clamp-1 max-w-[400px] font-mono text-[10px] text-muted-foreground">
                                                        {subLabel(sub)}
                                                    </span>
                                                </div>
                                                <div
                                                    className={`flex h-6 min-w-[80px] items-center justify-between rounded border px-3 ${sub.type === 'query' ? 'border-blue-500/20 bg-blue-500/10' : 'border-red-500/20 bg-red-500/10'}`}
                                                >
                                                    <span
                                                        className={`text-[10px] font-bold uppercase ${sub.type === 'query' ? 'text-blue-400' : 'text-red-400'}`}
                                                    >
                                                        {sub.type}
                                                    </span>
                                                    <span
                                                        className={`font-mono text-[10px] ${sub.type === 'query' ? 'text-blue-400' : 'text-red-400'}`}
                                                    >
                                                        {sub.type === 'query'
                                                            ? (
                                                                  (sub.payload
                                                                      .duration ||
                                                                      0) / 1000
                                                              ).toFixed(2) +
                                                              'ms'
                                                            : ''}
                                                    </span>
                                                </div>
                                            </div>
                                        ))}

                                        <div className="group flex items-center justify-between">
                                            <span className="text-[10px] font-bold tracking-tighter text-muted-foreground/60 uppercase">
                                                Controller
                                            </span>
                                            <div className="flex h-6 w-[40%] items-center justify-between rounded border border-border bg-muted px-3">
                                                <span className="text-[10px] font-bold uppercase">
                                                    Controller
                                                </span>
                                                <span className="font-mono text-[10px]">
                                                    {(
                                                        (payload.render || 0) /
                                                        1000
                                                    ).toFixed(2)}
                                                    ms
                                                </span>
                                            </div>
                                        </div>
                                    </>
                                )}

                                {!isRequest && (
                                    <div className="flex items-center justify-center p-8 text-xs text-muted-foreground italic">
                                        Detailed timeline not available for this
                                        record type.
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                </Card>
            </div>
        </>
    );
}

RecordShow.layout = (page: any) => (
    <AppLayout
        children={page}
        breadcrumbs={[
            { title: 'Records', href: '#' },
            { title: 'Details', href: '#' },
        ]}
    />
);
