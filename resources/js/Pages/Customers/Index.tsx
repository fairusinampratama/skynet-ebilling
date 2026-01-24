import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

interface Package {
    id: number;
    name: string;
    price: number;
    bandwidth_label: string;
}

interface Customer {
    id: number;
    internal_id: string;
    code: string;
    name: string;
    address: string;
    pppoe_user: string;
    status: 'active' | 'suspended' | 'isolated' | 'offboarding';
    package: Package;
    created_at: string;
    invoices?: Array<{
        id: number;
        due_date: string;
        status: string;
    }>;
}

interface PaginatedData {
    data: Customer[];
    links: any[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface Props {
    customers: PaginatedData;
    filters: {
        search?: string;
        status?: string;
    };
}

export default function Index({ customers, filters }: Props) {
    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || 'all');

    const handleSearch = () => {
        router.get('/customers', { search, status }, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handleReset = () => {
        setSearch('');
        setStatus('all');
        router.get('/customers', {}, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const getStatusBadge = (status: string) => {
        const variants: Record<string, string> = {
            active: 'text-emerald-500 border-emerald-500/20 bg-emerald-500/10',
            suspended: 'text-orange-500 border-orange-500/20 bg-orange-500/10',
            isolated: 'text-red-500 border-red-500/20 bg-red-500/10',
            offboarding: 'text-zinc-500 border-zinc-500/20 bg-zinc-500/10',
        };
        const className = variants[status] || 'text-zinc-500 border-zinc-500/20 bg-zinc-500/10';

        return (
            <Badge variant="outline" className={`${className} capitalize border`}>
                {status}
            </Badge>
        );
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-foreground">
                        Customer Management
                    </h2>
                    <Link href="/customers/create">
                        <Button className="bg-foreground text-background hover:bg-foreground/90">
                            Add Customer
                        </Button>
                    </Link>
                </div>
            }
        >
            <Head title="Customers" />

            <div className="py-8">
                <div className="space-y-6">
                    {/* Filters & Search - Floating Glass Bar */}
                    <div className="flex flex-col sm:flex-row gap-4 p-4 rounded-xl border border-border bg-card/50 backdrop-blur-sm">
                        <div className="flex-1">
                            <Input
                                placeholder="Search by name, PPPoE, address..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                                className="w-full bg-muted/50 border-border focus-visible:ring-ring"
                            />
                        </div>
                        <Select value={status} onValueChange={setStatus}>
                            <SelectTrigger className="w-[180px] bg-muted/50 border-border">
                                <SelectValue placeholder="Filter by status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Status</SelectItem>
                                <SelectItem value="active">Active</SelectItem>
                                <SelectItem value="suspended">Suspended</SelectItem>
                                <SelectItem value="isolated">Isolated</SelectItem>
                                <SelectItem value="offboarding">Offboarding</SelectItem>
                            </SelectContent>
                        </Select>
                        <div className="flex gap-2">
                            <Button onClick={handleSearch} variant="secondary">Search</Button>
                            <Button variant="ghost" onClick={handleReset}>Reset</Button>
                        </div>
                    </div>

                    {/* Main Table Card */}
                    <Card className="border-border bg-card/50 backdrop-blur-sm shadow-none">
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <div>
                                    <CardTitle>Customers Directory</CardTitle>
                                    <CardDescription>
                                        Managing {customers.total} registered subscribers
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div className="rounded-md border border-border overflow-hidden">
                                <Table>
                                    <TableHeader className="bg-muted/50">
                                        <TableRow className="border-border hover:bg-transparent">
                                            <TableHead className="w-[100px]">ID</TableHead>
                                            <TableHead>Customer Name</TableHead>
                                            <TableHead>Package Info</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Next Due Date</TableHead>
                                            <TableHead className="text-right">Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {customers.data.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={6} className="h-32 text-center text-muted-foreground">
                                                    No results found for your search.
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            customers.data.map((customer) => (
                                                <TableRow key={customer.id} className="group hover:bg-muted/50 border-border transition-colors">
                                                    <TableCell className="font-mono text-xs text-muted-foreground font-medium">
                                                        {customer.code || customer.internal_id}
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="flex flex-col">
                                                            <span className="font-medium text-foreground">{customer.name}</span>
                                                            <span className="text-xs text-muted-foreground truncate max-w-[200px]">
                                                                {customer.address}
                                                            </span>
                                                        </div>
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="flex flex-col gap-1">
                                                            <div className="flex items-center gap-2">
                                                                <span className="text-sm font-medium text-foreground">
                                                                    {customer.package.name}
                                                                </span>
                                                                <Badge variant="secondary" className="text-[10px] h-5 px-1.5 font-normal bg-muted text-muted-foreground hover:bg-muted/80">
                                                                    {customer.package.bandwidth_label}
                                                                </Badge>
                                                            </div>
                                                            <span className="text-xs font-mono text-muted-foreground">
                                                                Rp {customer.package.price.toLocaleString('id-ID')}
                                                            </span>
                                                        </div>
                                                    </TableCell>
                                                    <TableCell>{getStatusBadge(customer.status)}</TableCell>
                                                    <TableCell>
                                                        {customer.invoices && customer.invoices.length > 0 ? (
                                                            <div className={`text-sm flex flex-col ${new Date(customer.invoices[0].due_date) < new Date() && customer.invoices[0].status === 'unpaid'
                                                                ? 'text-red-500'
                                                                : 'text-muted-foreground'
                                                                }`}>
                                                                <span className="font-medium">
                                                                    {new Date(customer.invoices[0].due_date).toLocaleDateString('id-ID', {
                                                                        day: 'numeric',
                                                                        month: 'short'
                                                                    })}
                                                                </span>
                                                                {new Date(customer.invoices[0].due_date) < new Date() && customer.invoices[0].status === 'unpaid' && (
                                                                    <span className="text-[10px] font-bold uppercase">Overdue</span>
                                                                )}
                                                            </div>
                                                        ) : (
                                                            <span className="text-muted-foreground text-xs italic">No Active Bills</span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <Link
                                                            href={`/customers/${customer.id}`}
                                                            className="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 hover:bg-accent hover:text-accent-foreground h-9 w-9"
                                                        >
                                                            <span className="sr-only">View</span>
                                                            <svg width="15" height="15" viewBox="0 0 15 15" fill="none" xmlns="http://www.w3.org/2000/svg" className="h-4 w-4 text-muted-foreground group-hover:text-foreground">
                                                                <path d="M3.13523 6.15803C3.3241 5.95657 3.64052 5.94637 3.84197 6.13523L7.5 9.56464L11.158 6.13523C11.3595 5.94637 11.6759 5.95657 11.8648 6.15803C12.0536 6.35949 12.0434 6.67591 11.842 6.86477L7.84197 10.6148C7.64964 10.7951 7.35036 10.7951 7.15803 10.6148L3.15803 6.86477C2.95657 6.67591 2.94637 6.35949 3.13523 6.15803Z" fill="currentColor" transform="rotate(-90 7.5 7.5)" fillRule="evenodd" clipRule="evenodd"></path>
                                                            </svg>
                                                        </Link>
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </div>

                            {/* Pagination - Premium Style */}
                            <div className="mt-6 flex items-center justify-between border-t border-border pt-4">
                                <div className="text-sm text-muted-foreground">
                                    Showing <span className="font-medium text-foreground">{customers.data.length}</span> of <span className="font-medium text-foreground">{customers.total}</span> subscribers
                                </div>
                                <div className="flex gap-2">
                                    {customers.links.map((link, index) => (
                                        <Button
                                            key={index}
                                            variant={link.active ? 'default' : 'outline'}
                                            size="sm"
                                            className={!link.active ? 'border-border bg-transparent hover:bg-muted' : ''}
                                            disabled={!link.url}
                                            onClick={() => link.url && router.get(link.url)}
                                            dangerouslySetInnerHTML={{ __html: link.label }}
                                        />
                                    ))}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
