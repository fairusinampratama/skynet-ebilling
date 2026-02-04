import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { ArrowLeft, Server, Wifi, Activity, Users, RefreshCw, Edit, Trash2, Search, AlertCircle, ChevronLeft, ChevronRight } from 'lucide-react';
import { Skeleton } from '@/Components/ui/skeleton';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from "@/Components/ui/dialog";
import { useState, useEffect } from 'react';
import useSWR from 'swr';
import { formatDistanceToNow } from 'date-fns';
import RouterCustomersTable from './Partials/RouterCustomersTable';

interface Customer {
    id: number;
    name: string;
    code: string;
    pppoe_user: string;
    status: string;
    package: {
        name: string;
        price: number;
    } | null;
}

interface ActiveConnection {
    name: string;
    address: string;
    uptime: string;
    encoding: string;
    caller_id: string;
}

interface LiveStats {
    data: {
        active_connections: ActiveConnection[];
        total_online: number;
        system_info: any;
    };
    last_updated: string;
    cached?: boolean;
}

interface PaginatedCustomers {
    data: Customer[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number;
    to: number;
}

interface RouterData {
    id: number;
    name: string;
    ip_address: string;
    port: number;
    username: string;
    is_active: boolean;
    connection_status: 'unknown' | 'online' | 'offline';
    created_at: string;
    customers_count: number;
    last_scanned_at: string | null;
    last_scan_customers_count: number;
    total_pppoe_count: number;
    profiles: Array<{
        name: string;
        rate_limit?: string;
        bandwidth?: string;
        local_address?: string;
        remote_address?: string;
    }>;
}

interface Props {
    router: RouterData;
}

// SWR fetcher
const fetcher = async (url: string) => {
    const res = await fetch(url);
    if (!res.ok) {
        const data = await res.json();
        const error: any = new Error(data.error || 'An error occurred while fetching the data.');
        error.info = data;
        error.status = res.status;
        throw error;
    }
    return res.json();
};

export default function Show({ router: routerData }: Props) {
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [activeTab, setActiveTab] = useState('overview');

    // Use SWR for live stats with smart caching
    const { data: liveStats, error: statsError, isLoading: isLoadingStats, mutate } = useSWR<LiveStats>(
        `/api/routers/${routerData.id}/live-stats`,
        fetcher,
        {
            refreshInterval: 60000, // Refresh every 60 seconds (matches backend cache)
            revalidateOnFocus: false,
            dedupingInterval: 30000,
        }
    );

    const [isRefreshing, setIsRefreshing] = useState(false);

    // Manual Refresh (Overrides Scheduled Sync)
    const handleRefresh = () => {
        setIsRefreshing(true);
        router.post(`/routers/${routerData.id}/sync`, {}, {
            preserveScroll: true,
            onSuccess: () => {
                mutate(); // Trigger refresh but don't wait for it
            },
            onFinish: () => setIsRefreshing(false),
        });
    };

    const handleDelete = () => {
        router.delete(route('routers.destroy', routerData.id));
        setDeleteDialogOpen(false);
    };

    const getStatusColor = (status: string) => {
        switch (status.toLowerCase()) {
            case 'active':
                return 'bg-emerald-500/15 text-emerald-600 border-emerald-500/20';
            case 'isolated':
                return 'bg-red-500/15 text-red-600 border-red-500/20';
            case 'suspended':
                return 'bg-orange-500/15 text-orange-600 border-orange-500/20';
            default:
                return 'bg-gray-500/15 text-gray-600 border-gray-500/20';
        }
    };

    const formatDate = (dateString: string) => {
        return new Date(dateString).toLocaleDateString('id-ID', {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        });
    };

    return (
        <AuthenticatedLayout
            breadcrumbs={[
                { label: 'Routers', href: route('routers.index') },
                { label: routerData.name }
            ]}
            header={
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-4">
                        <Link href={route('routers.index')}>
                            <Button variant="ghost" size="icon" className="rounded-full">
                                <ChevronLeft className="h-5 w-5" />
                            </Button>
                        </Link>
                        <div>
                            <h2 className="text-xl font-semibold leading-tight text-foreground">
                                {routerData.name}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {routerData.ip_address}:{routerData.port}
                            </p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <Link href={route('routers.edit', routerData.id)}>
                            <Button variant="outline">
                                <Edit className="mr-2 h-4 w-4" />
                                Edit
                            </Button>
                        </Link>
                        <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
                            <DialogTrigger asChild>
                                <Button variant="destructive">
                                    <Trash2 className="mr-2 h-4 w-4" />
                                    Delete
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>Delete Router</DialogTitle>
                                    <DialogDescription>
                                        Are you sure you want to delete this router? This action cannot be undone.
                                        {routerData.customers_count > 0 && (
                                            <p className="mt-2 text-red-500 font-medium">
                                                Warning: This router has {routerData.customers_count} assigned customer(s).
                                            </p>
                                        )}
                                    </DialogDescription>
                                </DialogHeader>
                                <DialogFooter>
                                    <Button variant="outline" onClick={() => setDeleteDialogOpen(false)}>
                                        Cancel
                                    </Button>
                                    <Button variant="destructive" onClick={handleDelete}>
                                        Delete Router
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </div>
                </div>
            }
        >
            <Head title={`Router: ${routerData.name}`} />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-6">
                        {/* ... TabsList unchanged ... */}
                        <TabsList>
                            <TabsTrigger value="overview">
                                <Server className="h-4 w-4 mr-2" />
                                Overview
                            </TabsTrigger>
                            <TabsTrigger value="customers">
                                <Users className="h-4 w-4 mr-2" />
                                Customers ({routerData.customers_count})
                            </TabsTrigger>
                            <TabsTrigger value="profiles">
                                <Activity className="h-4 w-4 mr-2" />
                                Profiles ({String(routerData.profiles?.length || 0)})
                            </TabsTrigger>
                        </TabsList>

                        {/* Overview Tab */}
                        <TabsContent value="overview" className="space-y-6">
                            {/* Stats Grid */}
                            <div className="grid gap-6 md:grid-cols-3">
                                {/* ... Status Card unchanged ... */}
                                <Card className="border-border bg-card">
                                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                        <CardTitle className="text-sm font-medium text-muted-foreground">
                                            Connection Status
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <Badge
                                            variant={routerData.connection_status === 'online' ? 'default' : 'secondary'}
                                            className={routerData.connection_status === 'online' ? 'bg-emerald-500' : routerData.connection_status === 'offline' ? 'bg-red-500' : ''}
                                        >
                                            {routerData.connection_status === 'online' ? 'Online' :
                                                routerData.connection_status === 'offline' ? 'Offline' : 'Unknown'}
                                        </Badge>
                                        <p className="text-xs text-muted-foreground mt-2">
                                            {routerData.is_active ? 'Monitoring enabled' : 'Monitoring disabled'}
                                        </p>
                                    </CardContent>
                                </Card>

                                {/* ... Online/Total Card unchanged ... */}
                                <Card className="border-border bg-card">
                                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                        <CardTitle className="text-sm font-medium text-muted-foreground">
                                            Online / Total
                                        </CardTitle>
                                        <Users className="h-4 w-4 text-muted-foreground" />
                                    </CardHeader>
                                    <CardContent>
                                        {isLoadingStats ? (
                                            <Skeleton className="h-8 w-20" />
                                        ) : statsError ? (
                                            <div className="flex items-center text-sm text-red-500" title={statsError?.message || 'Failed to fetch stats'}>
                                                <AlertCircle className="h-4 w-4 mr-1" />
                                                {statsError?.message === 'Stream timed out' ? 'Unreachable' : 'Connection Failed'}
                                            </div>
                                        ) : (
                                            <div className="text-2xl font-bold">
                                                <span className="text-emerald-600">{liveStats?.data?.total_online || 0}</span>
                                                <span className="text-muted-foreground"> / {routerData.total_pppoe_count || routerData.customers_count}</span>
                                            </div>
                                        )}
                                        <p className="text-xs text-muted-foreground mt-1">
                                            {liveStats ? `Updated ${formatDistanceToNow(new Date(liveStats.last_updated))} ago` : 'Loading...'}
                                        </p>
                                    </CardContent>
                                </Card>

                                {/* ... API Port Card unchanged ... */}
                                <Card className="border-border bg-card">
                                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                        <CardTitle className="text-sm font-medium text-muted-foreground">
                                            API Port
                                        </CardTitle>
                                        <Server className="h-4 w-4 text-muted-foreground" />
                                    </CardHeader>
                                    <CardContent>
                                        <div className="text-2xl font-bold">{routerData.port}</div>
                                        <p className="text-xs text-muted-foreground mt-1">
                                            RouterOS API
                                        </p>
                                    </CardContent>
                                </Card>
                            </div>

                            {/* Last Scan Info - Always Visible */}
                            <Card className="border-border bg-card">
                                <CardContent className="pt-6">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-3">
                                            <Activity className="h-5 w-5 text-muted-foreground" />
                                            <div>
                                                <p className="text-sm font-medium">Last Full Sync</p>
                                                <p className="text-xs text-muted-foreground">
                                                    {routerData.last_scanned_at
                                                        ? `${formatDistanceToNow(new Date(routerData.last_scanned_at))} ago • Found ${routerData.last_scan_customers_count} customers`
                                                        : 'Never synced'
                                                    }
                                                </p>
                                            </div>
                                        </div>
                                        {/* Subtle Refresh Button */}
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={handleRefresh}
                                            disabled={isRefreshing}
                                            title={routerData.is_active ? "Force Manual Sync" : "Sync to Reactivate Router"}
                                        >
                                            <RefreshCw className={`h-4 w-4 ${isRefreshing ? 'animate-spin' : ''}`} />
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>

                            {/* Active Connections */}
                            {liveStats?.data?.active_connections && Array.isArray(liveStats.data.active_connections) && liveStats.data.active_connections.length > 0 && (
                                <Card className="border-border bg-card">
                                    <CardHeader>
                                        <div className="flex items-center justify-between">
                                            <CardTitle>Active Connections</CardTitle>
                                            <Badge variant="outline" className="text-emerald-600">
                                                {liveStats.data.total_online} online
                                            </Badge>
                                        </div>
                                        <CardDescription>
                                            Currently connected PPPoE sessions (showing first 10)
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="space-y-3">
                                            {liveStats.data.active_connections.slice(0, 10).map((conn, idx) => (
                                                <div key={idx} className="flex items-center justify-between p-3 rounded-lg bg-muted/50 hover:bg-muted transition-colors">
                                                    <div>
                                                        <p className="font-medium">{conn.name}</p>
                                                        <p className="text-xs text-muted-foreground">{conn.address}</p>
                                                    </div>
                                                    <div className="text-right">
                                                        <p className="text-xs font-mono text-emerald-600">{conn.uptime}</p>
                                                        <p className="text-xs text-muted-foreground">{conn.encoding}</p>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </CardContent>
                                </Card>
                            )}

                            {/* Configuration Details */}
                            <Card className="border-border bg-card">
                                <CardHeader>
                                    <CardTitle>Router Configuration</CardTitle>
                                    <CardDescription>
                                        Technical details and credentials
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <div className="grid grid-cols-2 gap-6">
                                        <div>
                                            <p className="text-sm font-medium text-muted-foreground">Router Name</p>
                                            <p className="text-base font-semibold mt-1">{routerData.name}</p>
                                        </div>
                                        <div>
                                            <p className="text-sm font-medium text-muted-foreground">Username</p>
                                            <p className="text-base font-mono mt-1">{routerData.username}</p>
                                        </div>
                                        <div>
                                            <p className="text-sm font-medium text-muted-foreground">IP Address</p>
                                            <p className="text-base font-mono mt-1">{routerData.ip_address}:{routerData.port}</p>
                                        </div>
                                        <div>
                                            <p className="text-sm font-medium text-muted-foreground">Created</p>
                                            <p className="text-base mt-1">{formatDate(routerData.created_at)}</p>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        </TabsContent>

                        {/* Customers Tab */}
                        <TabsContent value="customers">
                            <Card className="border-border bg-card">
                                <CardHeader>
                                    <CardTitle>Customers</CardTitle>
                                    <CardDescription>
                                        Managed customers assigned to this router.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    {activeTab === 'customers' && (
                                        <RouterCustomersTable
                                            routerId={routerData.id}
                                            activeConnections={liveStats?.data?.active_connections || []}
                                        />
                                    )}
                                </CardContent>
                            </Card>
                        </TabsContent>

                        {/* Profiles Tab */}
                        <TabsContent value="profiles" className="space-y-4">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Synced Profiles</CardTitle>
                                    <CardDescription>
                                        PPP Profiles synced from this router. Used for creating packages.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    {(!routerData.profiles || routerData.profiles.length === 0) ? (
                                        <div className="text-center py-12 border-2 border-dashed rounded-lg">
                                            <AlertCircle className="h-10 w-10 text-muted-foreground mx-auto mb-3" />
                                            <h3 className="text-lg font-medium">No Profiles Synced</h3>
                                            <p className="text-muted-foreground mb-4">
                                                Run a "Full Sync" to fetch profiles from the router.
                                            </p>
                                        </div>
                                    ) : (
                                        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                                            {routerData.profiles.map((profile) => (
                                                <div key={profile.name} className="flex items-start justify-between p-4 border rounded-lg hover:bg-slate-50 transition-colors">
                                                    <div>
                                                        <div className="flex items-center gap-2 mb-1">
                                                            <span className="font-mono font-semibold text-lg">{profile.name}</span>
                                                            {profile.bandwidth && (
                                                                <Badge variant="secondary">{profile.bandwidth}</Badge>
                                                            )}
                                                        </div>
                                                        <div className="text-xs text-muted-foreground font-mono space-y-1">
                                                            {profile.rate_limit && (
                                                                <div className="flex items-center gap-1">
                                                                    <Activity className="h-3 w-3" />
                                                                    {profile.rate_limit}
                                                                </div>
                                                            )}
                                                            {profile.local_address && (
                                                                <div className="flex items-center gap-1">
                                                                    <Server className="h-3 w-3" />
                                                                    Local: {profile.local_address}
                                                                </div>
                                                            )}
                                                            {profile.remote_address && (
                                                                <div className="flex items-center gap-1">
                                                                    <Wifi className="h-3 w-3" />
                                                                    Remote: {profile.remote_address}
                                                                </div>
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </TabsContent>
                    </Tabs>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
