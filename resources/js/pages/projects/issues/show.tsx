import { Head, useForm, usePage, router } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import {
    User as UserIcon,
    MessageSquare,
    History,
    CheckCircle2,
    AlertCircle,
    Send,
    Clock,
    UserPlus,
    Tag,
    ChevronRight,
    Activity,
    FileCode,
    Cpu,
    Database,
} from 'lucide-react';
import { atomDark } from 'react-syntax-highlighter/dist/esm/styles/prism';
import { CodeBlock } from '@/components/code-block';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';

export default function IssueShow({
    issue,
    team_members,
}: {
    issue: any;
    team_members: any;
}) {
    const { props }: any = usePage();
    const latestRecord = issue.records?.[0] || null;
    const payload = latestRecord?.payload || {};

    const teamSlug = props.current_team?.slug || props.currentTeam?.slug;
    const projectSlug =
        props.current_project?.slug || props.currentProject?.slug;

    const { data, setData, post, processing, reset } = useForm({
        comment: '',
    });

    const updateIssue = (key: string, value: any) => {
        const finalValue = value === 'unassigned' ? null : value;
        router.patch(
            `/${teamSlug}/${projectSlug}/issues/${issue.id}`,
            { [key]: finalValue },
            { preserveScroll: true },
        );
    };

    const submitComment = (e: React.FormEvent) => {
        e.preventDefault();

        if (!data.comment.trim()) {
            return;
        }

        post(`/${teamSlug}/${projectSlug}/issues/${issue.id}/comments`, {
            onSuccess: () => reset('comment'),
            preserveScroll: true,
        });
    };

    const getActivityIcon = (type: string) => {
        switch (type) {
            case 'status_change':
                return <CheckCircle2 className="h-3.5 w-3.5 text-primary" />;
            case 'priority_change':
                return <AlertCircle className="h-3.5 w-3.5 text-primary" />;
            case 'comment':
                return <MessageSquare className="h-3.5 w-3.5 text-primary" />;
            case 'assignment':
                return <UserPlus className="h-3.5 w-3.5 text-primary" />;
            default:
                return (
                    <History className="h-3.5 w-3.5 text-muted-foreground" />
                );
        }
    };

    const stackTrace = Array.isArray(payload.stack) ? payload.stack : [];

    return (
        <div className="mx-auto max-w-[1600px] animate-in space-y-6 duration-700 fade-in slide-in-from-bottom-4">
            <Head title={`Issue #${issue.id} - ${issue.title}`} />

            {/* Top Header Section */}
            <div className="flex flex-col justify-between gap-4 border-b border-border/50 pb-6 md:flex-row md:items-center">
                <div className="flex-1 space-y-2">
                    <div className="flex items-center gap-2 text-[10px] font-black tracking-widest text-muted-foreground uppercase">
                        <span className="rounded border border-primary/10 bg-primary/5 px-1.5 py-0.5 text-primary">
                            Issue #{issue.id}
                        </span>
                        <ChevronRight className="size-3" />
                        <span>{issue.type}</span>
                    </div>
                    <h1 className="text-2xl leading-tight font-black tracking-tight break-all whitespace-normal text-foreground uppercase">
                        {issue.title}
                    </h1>
                    <div className="flex items-center gap-3">
                        <p className="text-sm font-medium break-all whitespace-normal text-muted-foreground">
                            {issue.message}
                        </p>
                        {payload.file && (
                            <div className="flex items-center gap-1.5 rounded-full border border-primary/10 bg-primary/5 px-2 py-0.5 text-[10px] font-bold text-primary/60">
                                <FileCode className="size-3" />
                                {payload.file}:{payload.line}
                            </div>
                        )}
                    </div>
                </div>

                <div className="flex items-center gap-2">
                    <Badge
                        variant="outline"
                        className="h-8 rounded-lg border-primary/20 bg-primary/5 px-3 text-[10px] font-bold tracking-widest text-primary uppercase"
                    >
                        {issue.status}
                    </Badge>
                    <Badge
                        variant="outline"
                        className="h-8 rounded-lg border-border bg-card px-3 text-[10px] font-bold tracking-widest text-muted-foreground uppercase"
                    >
                        {issue.priority} Priority
                    </Badge>
                </div>
            </div>

            <div className="grid grid-cols-1 gap-8 lg:grid-cols-12">
                {/* Main Content */}
                <div className="space-y-8 lg:col-span-8">
                    <div className="mb-4 flex items-center justify-between overflow-x-auto">
                        {stackTrace.length > 0 ? (
                            <div className="flex items-center gap-2 text-[10px] font-black tracking-widest text-muted-foreground uppercase">
                                <FileCode className="size-3 text-primary" />
                                Stack Trace
                            </div>
                        ) : (
                            <div className="flex items-center gap-2 text-[10px] font-black tracking-widest text-muted-foreground uppercase">
                                <Database className="size-3 text-primary" />
                                Raw Payload
                            </div>
                        )}

                        <div className="hidden items-center gap-2 sm:flex">
                            <div className="flex items-center gap-1.5 rounded-full border border-border/50 bg-muted/20 px-3 py-1 text-[9px] font-black text-muted-foreground uppercase">
                                <Cpu className="size-3" /> PHP{' '}
                                {payload.php_version ||
                                    payload.system?.php_version ||
                                    payload.payload?.system?.php_version ||
                                    payload.environment?.php_version ||
                                    payload.server?.php_version ||
                                    'Unknown'}
                            </div>
                            <div className="flex items-center gap-1.5 rounded-full border border-border/50 bg-muted/20 px-3 py-1 text-[9px] font-black text-muted-foreground uppercase">
                                <Database className="size-3" /> Laravel{' '}
                                {payload.laravel_version ||
                                    payload.system?.laravel_version ||
                                    payload.payload?.system?.laravel_version ||
                                    payload.environment?.laravel_version ||
                                    payload.server?.laravel_version ||
                                    'Unknown'}
                            </div>
                        </div>
                    </div>

                    {/* Stack Trace View */}
                    {stackTrace.length > 0 && (
                        <section>
                            <div className="space-y-4">
                                {stackTrace
                                    .slice(0, 15)
                                    .map((frame: any, i: number) => (
                                        <Card
                                            key={i}
                                            className="group overflow-hidden border-border bg-card/50 shadow-sm"
                                        >
                                            <div className="flex items-center justify-between border-b border-border/50 bg-muted/30 px-4 py-2">
                                                <div className="flex items-center gap-2 truncate">
                                                    <span className="text-[10px] font-black text-primary/40">
                                                        #{stackTrace.length - i}
                                                    </span>
                                                    <code className="truncate text-[10px] font-bold text-foreground">
                                                        {frame.file}:
                                                        {frame.line}
                                                    </code>
                                                </div>
                                                <span className="text-[9px] font-black text-muted-foreground uppercase opacity-0 transition-opacity group-hover:opacity-100">
                                                    {frame.class || 'global'}::
                                                    {frame.function}
                                                </span>
                                            </div>
                                            <CardContent className="p-0">
                                                <CodeBlock
                                                    language="php"
                                                    style={atomDark}
                                                    customStyle={{
                                                        margin: 0,
                                                        borderRadius: 0,
                                                        fontSize: '11px',
                                                        background:
                                                            'transparent',
                                                        padding: '1.5rem',
                                                    }}
                                                    showLineNumbers={true}
                                                    startingLineNumber={Math.max(
                                                        1,
                                                        (frame.line || 1) - 5,
                                                    )}
                                                    wrapLines={true}
                                                    lineProps={(
                                                        lineNum: number,
                                                    ) => {
                                                        const style: any = {
                                                            display: 'block',
                                                        };

                                                        if (
                                                            lineNum ===
                                                            frame.line
                                                        ) {
                                                            style.backgroundColor =
                                                                'rgba(var(--primary), 0.1)';
                                                            style.borderLeft =
                                                                '2px solid rgb(var(--primary))';
                                                        }

                                                        return { style };
                                                    }}
                                                >
                                                    {frame.snippet ||
                                                        '// No code snippet available'}
                                                </CodeBlock>
                                            </CardContent>
                                        </Card>
                                    ))}
                            </div>
                        </section>
                    )}

                    {/* JSON View */}
                    <section>
                        <Card className="overflow-hidden border-border bg-black shadow-2xl">
                            <CardContent className="p-0">
                                <CodeBlock
                                    language="json"
                                    style={atomDark}
                                    customStyle={{
                                        margin: 0,
                                        borderRadius: 0,
                                        fontSize: '11px',
                                        padding: '2rem',
                                        background: 'rgba(0,0,0,0.4)',
                                    }}
                                >
                                    {JSON.stringify(payload, null, 4)}
                                </CodeBlock>
                            </CardContent>
                        </Card>
                    </section>

                    {/* Activity Feed */}
                    <section className="space-y-4 border-t border-border/50 pt-8">
                        <div className="flex items-center gap-2 text-[10px] font-black tracking-widest text-muted-foreground uppercase">
                            <History className="size-3 text-primary" />
                            Collaboration & Logs
                        </div>
                        <div className="space-y-4">
                            {issue.activities?.map((activity: any) => (
                                <div
                                    key={activity.id}
                                    className="group relative pb-4 pl-8"
                                >
                                    <div className="absolute top-0 bottom-0 left-[11px] w-px bg-border group-last:bg-transparent" />
                                    <div className="absolute top-1 left-0 flex size-6 items-center justify-center rounded-full border border-border bg-card shadow-sm">
                                        {getActivityIcon(activity.type)}
                                    </div>
                                    <div className="space-y-1.5">
                                        <div className="flex items-center gap-2">
                                            <span className="text-[11px] font-bold text-foreground">
                                                {activity.user?.name ||
                                                    'System'}
                                            </span>
                                            <span className="text-[10px] text-muted-foreground">
                                                {formatDistanceToNow(
                                                    new Date(
                                                        activity.created_at,
                                                    ),
                                                )}{' '}
                                                ago
                                            </span>
                                        </div>
                                        {activity.type === 'comment' ? (
                                            <div className="max-w-[600px] rounded-xl border border-border/50 bg-muted/20 p-3 text-xs text-muted-foreground">
                                                {activity.content}
                                            </div>
                                        ) : (
                                            <p className="text-xs text-muted-foreground">
                                                {activity.content}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            ))}

                            {/* Comment Form */}
                            <div className="relative pl-8">
                                <div className="absolute top-1 left-0 flex size-6 items-center justify-center rounded-full border border-primary/20 bg-primary/10">
                                    <MessageSquare className="size-3 text-primary" />
                                </div>
                                <Card className="overflow-hidden border-border bg-card/50 shadow-sm">
                                    <form onSubmit={submitComment}>
                                        <textarea
                                            className="min-h-[100px] w-full resize-none border-none bg-transparent p-4 text-xs placeholder:text-muted-foreground/50 focus:ring-0"
                                            placeholder="Write your observation..."
                                            value={data.comment}
                                            onChange={(e) =>
                                                setData(
                                                    'comment',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <div className="flex justify-end border-t border-border/50 bg-muted/20 p-2">
                                            <Button
                                                size="sm"
                                                disabled={
                                                    processing ||
                                                    !data.comment.trim()
                                                }
                                                className="h-8 gap-2 rounded-lg px-4 text-[10px] font-black tracking-widest uppercase"
                                            >
                                                Post Comment
                                                <Send className="size-3" />
                                            </Button>
                                        </div>
                                    </form>
                                </Card>
                            </div>
                        </div>
                    </section>
                </div>

                {/* Sidebar */}
                <div className="space-y-6 lg:col-span-4">
                    <Card className="sticky top-6 border-border bg-card/50 shadow-sm">
                        <CardHeader className="border-b border-border/50 bg-muted/10">
                            <CardTitle className="text-[10px] font-black tracking-widest text-muted-foreground uppercase">
                                Management
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-6 p-6">
                            <div className="space-y-4">
                                <div className="space-y-2">
                                    <label className="flex items-center gap-1.5 text-[10px] font-bold text-muted-foreground uppercase">
                                        <Tag className="size-3" /> Status
                                    </label>
                                    <Select
                                        value={issue.status}
                                        onValueChange={(v) =>
                                            updateIssue('status', v)
                                        }
                                    >
                                        <SelectTrigger className="h-10 rounded-xl border-border/50 bg-background">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="open">
                                                Open
                                            </SelectItem>
                                            <SelectItem value="resolved">
                                                Resolved
                                            </SelectItem>
                                            <SelectItem value="ignored">
                                                Ignored
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="space-y-2">
                                    <label className="flex items-center gap-1.5 text-[10px] font-bold text-muted-foreground uppercase">
                                        <AlertCircle className="size-3" />{' '}
                                        Priority
                                    </label>
                                    <Select
                                        value={issue.priority}
                                        onValueChange={(v) =>
                                            updateIssue('priority', v)
                                        }
                                    >
                                        <SelectTrigger className="h-10 rounded-xl border-border/50 bg-background">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">
                                                No Priority
                                            </SelectItem>
                                            <SelectItem value="low">
                                                Low
                                            </SelectItem>
                                            <SelectItem value="medium">
                                                Medium
                                            </SelectItem>
                                            <SelectItem value="high">
                                                High
                                            </SelectItem>
                                            <SelectItem value="critical">
                                                Critical
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="space-y-2">
                                    <label className="flex items-center gap-1.5 text-[10px] font-bold text-muted-foreground uppercase">
                                        <UserIcon className="size-3" /> Assignee
                                    </label>
                                    <Select
                                        value={
                                            issue.assigned_to?.toString() ||
                                            'unassigned'
                                        }
                                        onValueChange={(v) =>
                                            updateIssue('assigned_to', v)
                                        }
                                    >
                                        <SelectTrigger className="h-10 rounded-xl border-border/50 bg-background">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="unassigned">
                                                Unassigned
                                            </SelectItem>
                                            {team_members?.map((m: any) => (
                                                <SelectItem
                                                    key={m.id}
                                                    value={m.id.toString()}
                                                >
                                                    {m.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div className="space-y-4 border-t border-border/50 pt-6">
                                <div className="flex items-center justify-between text-xs">
                                    <span className="flex items-center gap-2 font-medium text-muted-foreground">
                                        <Clock className="size-3" /> First seen
                                    </span>
                                    <span className="font-bold text-foreground">
                                        {issue.first_seen_at
                                            ? formatDistanceToNow(
                                                  new Date(issue.first_seen_at),
                                              )
                                            : 'N/A'}{' '}
                                        ago
                                    </span>
                                </div>
                                <div className="flex items-center justify-between text-xs">
                                    <span className="flex items-center gap-2 font-medium text-muted-foreground">
                                        <History className="size-3" /> Last seen
                                    </span>
                                    <span className="font-bold text-foreground">
                                        {issue.last_seen_at
                                            ? formatDistanceToNow(
                                                  new Date(issue.last_seen_at),
                                              )
                                            : 'N/A'}{' '}
                                        ago
                                    </span>
                                </div>
                                <div className="flex items-center justify-between text-xs">
                                    <span className="flex items-center gap-2 font-medium text-muted-foreground">
                                        <Activity className="size-3" />{' '}
                                        Occurrences
                                    </span>
                                    <Badge
                                        variant="secondary"
                                        className="px-2 py-0 font-black"
                                    >
                                        {issue.occurrences_count}
                                    </Badge>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    );
}

IssueShow.layout = (page: any) => (
    <AppLayout
        children={page}
        breadcrumbs={[
            { title: 'Issues', href: '#' },
            { title: 'Details', href: '#' },
        ]}
    />
);
