import { useState } from 'react';
import useSWR from 'swr';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Search, RotateCw, MoreHorizontal, Eye, Edit } from 'lucide-react';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "@/components/ui/dropdown-menu";
import { PaginatedData } from '@/Components/DataTable';

interface Customer {
    id: number;
    name: string;
    code: string;
    pppoe_user: string;
    status: string;
    package?: {
        name: string;
    };
    created_at: string;
}

interface ActiveConnection {
    name: string;
    address: string;
    uptime: string;
    // ... other props
}

interface RouterCustomersTableProps {
    routerId: number;
    activeConnections: ActiveConnection[]; // Passed from parent (live stats)
}

const fetcher = (url: string) => fetch(url).then((res) => res.json());

export default function RouterCustomersTable({ routerId, activeConnections }: RouterCustomersTableProps) {
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');
    const [debouncedSearch, setDebouncedSearch] = useState('');

    // Debounce search input
    const handleSearchChange = (value: string) => {
        setSearch(value);
        const timer = setTimeout(() => setDebouncedSearch(value), 500);
        return () => clearTimeout(timer);
    };

    // Construct URL for SWR
    const queryParams = new URLSearchParams({
        page: page.toString(),
        search: debouncedSearch,
        status: statusFilter,
    });

    const { data: customersData, error, isLoading, mutate } = useSWR<PaginatedData<Customer>>(
        `/api/routers/${routerId}/customers?${queryParams.toString()}`,
        fetcher,
        {
            keepPreviousData: true, // Keep showing old data while fetching new page
        }
    );

    const checkIsOnline = (pppoeUser: string) => {
        return activeConnections?.some(conn => conn.name === pppoeUser);
    };

    const getStatusColor = (status: string) => {
        switch (status) {
            case 'active': return 'text-emerald-500 border-emerald-500/20 bg-emerald-500/10';
            case 'isolated': return 'text-red-500 border-red-500/20 bg-red-500/10';
            default: return 'text-zinc-500 border-zinc-500/20 bg-zinc-500/10';
        }
    };

    return (
        <div className="space-y-4">
            {/* Toolbar */}
            <div className="flex flex-col sm:flex-row gap-4 justify-between items-center bg-muted/30 p-4 rounded-lg border border-border">
                <div className="relative w-full sm:w-72">
                    <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                    <Input
                        placeholder="Search by name, PPPoE..."
                        value={search}
                        onChange={(e) => handleSearchChange(e.target.value)}
                        className="pl-9 bg-background"
                    />
                </div>

                <div className="flex items-center gap-2 w-full sm:w-auto">
                    <Select value={statusFilter} onValueChange={(val) => { setStatusFilter(val); setPage(1); }}>
                        <SelectTrigger className="w-[180px] bg-background">
                            <SelectValue placeholder="Filter Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Status</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="isolated">Isolated</SelectItem>
                        </SelectContent>
                    </Select>

                    <Button variant="outline" size="icon" onClick={() => mutate()} title="Refresh Data">
                        <RotateCw className={`h-4 w-4 ${isLoading ? 'animate-spin' : ''}`} />
                    </Button>
                </div>
            </div>

            {/* Table */}
            <div className="rounded-md border border-border overflow-hidden bg-card">
                <Table>
                    <TableHeader className="bg-muted/50">
                        <TableRow>
                            <TableHead>Code</TableHead>
                            <TableHead>Customer Name</TableHead>
                            <TableHead>PPPoE Account</TableHead>
                            <TableHead>Package</TableHead>
                            <TableHead>Connection</TableHead>
                            <TableHead>Billing Status</TableHead>
                            <TableHead className="text-right">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {isLoading && !customersData ? (
                            Array.from({ length: 5 }).map((_, i) => (
                                <TableRow key={i}>
                                    <TableCell><Skeleton className="h-4 w-12" /></TableCell>
                                    <TableCell><Skeleton className="h-4 w-32" /></TableCell>
                                    <TableCell><Skeleton className="h-4 w-24" /></TableCell>
                                    <TableCell><Skeleton className="h-4 w-20" /></TableCell>
                                    <TableCell><Skeleton className="h-4 w-16" /></TableCell>
                                    <TableCell><Skeleton className="h-4 w-16" /></TableCell>
                                    <TableCell><Skeleton className="h-4 w-8 ml-auto" /></TableCell>
                                </TableRow>
                            ))
                        ) : error ? (
                            <TableRow>
                                <TableCell colSpan={7} className="h-24 text-center text-red-500">
                                    Failed to load customers. Please try refreshing.
                                </TableCell>
                            </TableRow>
                        ) : customersData?.data.length === 0 ? (
                            <TableRow>
                                <TableCell colSpan={7} className="h-24 text-center text-muted-foreground">
                                    No customers found matching your criteria.
                                </TableCell>
                            </TableRow>
                        ) : (
                            customersData?.data.map((customer) => {
                                const isOnline = checkIsOnline(customer.pppoe_user);
                                return (
                                    <TableRow key={customer.id} className="hover:bg-muted/20 transition-colors">
                                        <TableCell className="font-mono text-xs">{customer.code}</TableCell>
                                        <TableCell className="font-medium">{customer.name}</TableCell>
                                        <TableCell className="font-mono text-sm text-muted-foreground">{customer.pppoe_user}</TableCell>
                                        <TableCell>{customer.package?.name || '-'}</TableCell>
                                        <TableCell>
                                            <Badge variant={isOnline ? 'default' : 'secondary'} className={isOnline ? 'bg-emerald-500 hover:bg-emerald-600' : ''}>
                                                {isOnline ? 'Online' : 'Offline'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant="outline" className={getStatusColor(customer.status)}>
                                                {customer.status}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button variant="ghost" className="h-8 w-8 p-0">
                                                        <span className="sr-only">Open menu</span>
                                                        <MoreHorizontal className="h-4 w-4" />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuItem onClick={() => router.visit(route('customers.show', customer.id))}>
                                                        <Eye className="mr-2 h-4 w-4" />
                                                        View Details
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem onClick={() => router.visit(route('customers.edit', customer.id))}>
                                                        <Edit className="mr-2 h-4 w-4" />
                                                        Edit Customer
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </TableCell>
                                    </TableRow>
                                );
                            })
                        )}
                    </TableBody>
                </Table>
            </div>

            {/* Pagination Controls */}
            {customersData && customersData.last_page > 1 && (
                <div className="flex items-center justify-between border-t border-border pt-4">
                    <p className="text-sm text-muted-foreground">
                        Showing <span className="font-medium">{customersData.from}</span> to <span className="font-medium">{customersData.to}</span> of <span className="font-medium">{customersData.total}</span> customers
                    </p>
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setPage(p => Math.max(1, p - 1))}
                            disabled={page === 1 || isLoading}
                        >
                            <ChevronLeft className="h-4 w-4 mr-1" /> Previous
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setPage(p => Math.min(customersData.last_page, p + 1))}
                            disabled={page === customersData.last_page || isLoading}
                        >
                            Next <ChevronRight className="h-4 w-4 ml-1" />
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}
