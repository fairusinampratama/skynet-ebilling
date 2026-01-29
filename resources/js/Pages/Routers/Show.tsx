import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ArrowLeft, Server, Wifi, Activity, Users, TestTube, RefreshCw, Edit, Trash2, Search, AlertCircle, ChevronLeft, ChevronRight } from 'lucide-react';
import { Skeleton } from '../../components/ui/skeleton';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from "@/components/ui/dialog";
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
    created_at: string;
    customers_count: number;
    last_scanned_at: string | null;
    last_scan_customers_count: number;
    total_pppoe_count: number;
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
    const { data: liveStats, error: statsError, isLoading: isLoadingStats } = useSWR<LiveStats>(
        `/api/routers/${routerData.id}/live-stats`,
        fetcher,
        {
            refreshInterval: 60000, // Refresh every 60 seconds (matches backend cache)
            revalidateOnFocus: false,
            dedupingInterval: 30000,
        }
    );



    const [isTestingConnection, setIsTestingConnection] = useState(false);
    const [isScanning, setIsScanning] = useState(false);

    const handleTestConnection = () => {
        setIsTestingConnection(true);
        router.post(`/routers/${routerData.id}/test-connection`, {}, {
            preserveScroll: true,
            onFinish: () => setIsTestingConnection(false),
        });
    };

    const handleScanRouter = () => {
        setIsScanning(true);
        router.post(`/routers/${routerData.id}/scan`, {}, {
            preserveScroll: true,
            onFinish: () => setIsScanning(false),
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
                        <Button variant="outline" onClick={handleTestConnection} disabled={isTestingConnection}>
                            {isTestingConnection ? (
                                <RefreshCw className="mr-2 h-4 w-4 animate-spin" />
                            ) : (
                                <TestTube className="mr-2 h-4 w-4" />
                            )}
                            {isTestingConnection ? 'Testing...' : 'Test Connection'}
                        </Button>
                        <Button variant="outline" onClick={handleScanRouter} disabled={isScanning || !routerData.is_active}>
                            <RefreshCw className={`mr-2 h-4 w-4 ${isScanning ? 'animate-spin' : ''}`} />
                            {isScanning ? 'Starting Scan...' : 'Scan Customers'}
                        </Button>
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
                        <TabsList>
                            <TabsTrigger value="overview">
                                <Server className="h-4 w-4 mr-2" />
                                Overview
                            </TabsTrigger>
                            <TabsTrigger value="customers">
                                <Users className="h-4 w-4 mr-2" />
                                Customers ({routerData.customers_count})
                            </TabsTrigger>
                        </TabsList>

                        {/* Overview Tab */}
                        <TabsContent value="overview" className="space-y-6">
                            {/* Stats Grid */}
                            <div className="grid gap-6 md:grid-cols-3">
                                <Card className="border-border bg-card">
                                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                        <CardTitle className="text-sm font-medium text-muted-foreground">
                                            Status
                                        </CardTitle>
                                        <Wifi className="h-4 w-4 text-muted-foreground" />
                                    </CardHeader>
                                    <CardContent>
                                        <Badge variant={routerData.is_active ? 'default' : 'secondary'}>
                                            {routerData.is_active ? 'Active' : 'Inactive'}
                                        </Badge>
                                    </CardContent>
                                </Card>

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

                            {/* Last Scan Info */}
                            {routerData.last_scanned_at && (
                                <Card className="border-border bg-card">
                                    <CardContent className="pt-6">
                                        <div className="flex items-center justify-between">
                                            <div className="flex items-center gap-3">
                                                <Activity className="h-5 w-5 text-muted-foreground" />
                                                <div>
                                                    <p className="text-sm font-medium">Last Network Scan</p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {formatDistanceToNow(new Date(routerData.last_scanned_at))} ago • Found {routerData.last_scan_customers_count} customers
                                                    </p>
                                                </div>
                                            </div>
                                            <Button variant="outline" size="sm" onClick={handleScanRouter}>
                                                <RefreshCw className="h-4 w-4 mr-2" />
                                                Refresh
                                            </Button>
                                        </div>
                                    </CardContent>
                                </Card>
                            )}

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
                    </Tabs>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
