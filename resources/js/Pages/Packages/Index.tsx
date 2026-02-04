import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "@/Components/ui/dropdown-menu";
import { MoreHorizontal, Eye, Edit } from 'lucide-react';
import { router } from '@inertiajs/react';
import { EditAction, DeleteAction } from '@/Components/TableActions';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import { useState } from 'react';

interface Package {
    id: number;
    name: string;
    price: number;
    bandwidth_label: string;
    mikrotik_profile?: string;
    rate_limit?: string;
    router?: {
        id: number;
        name: string;
    };
    customers_count: number;
}

interface Props {
    packages: Package[];
}

export default function Index({ packages }: Props) {
    const formatCurrency = (amount: number) => {
        return new Intl.NumberFormat('id-ID', {
            style: 'currency',
            currency: 'IDR',
            minimumFractionDigits: 0,
        }).format(amount);
    };

    const [selectedRouter, setSelectedRouter] = useState<string>('all');

    // Get unique routers from packages for filter dropdown
    const routers = Array.from(new Set(packages.map(p => p.router ? JSON.stringify({ id: p.router.id, name: p.router.name }) : null)))
        .filter(Boolean)
        .map(s => JSON.parse(s as string));

    const filteredPackages = packages.filter(pkg => {
        if (selectedRouter === 'all') return true;
        if (selectedRouter === 'global') return !pkg.router;
        return pkg.router?.id.toString() === selectedRouter;
    });

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-foreground">
                        Packages
                    </h2>
                    <Link href="/packages/create">
                        <Button className="bg-foreground text-background hover:bg-foreground/90">
                            Create Package
                        </Button>
                    </Link>
                </div>
            }
        >
            <Head title="Packages" />

            <div className="py-8">
                <div className="mx-auto max-w-7xl">

                    {/* Filter Bar */}
                    <div className="mb-6 flex items-center gap-4">
                        <div className="w-64">
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="outline" className="w-full justify-between">
                                        {selectedRouter === 'all' ? 'All Routers' :
                                            selectedRouter === 'global' ? 'Global Only' :
                                                routers.find(r => r.id.toString() === selectedRouter)?.name || 'Filter by Router'}
                                        <MoreHorizontal className="ml-2 h-4 w-4 rotate-90" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent className="w-64" align="start">
                                    <DropdownMenuItem onClick={() => setSelectedRouter('all')}>
                                        All Routers
                                    </DropdownMenuItem>
                                    {routers.map(router => (
                                        <DropdownMenuItem key={router.id} onClick={() => setSelectedRouter(router.id.toString())}>
                                            {router.name}
                                        </DropdownMenuItem>
                                    ))}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </div>

                    <Card className="border-border bg-card/50 backdrop-blur-sm shadow-none">
                        <CardHeader>
                            <CardTitle>Package Management</CardTitle>
                            <CardDescription>
                                {filteredPackages.length} package(s) shown
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="rounded-md border border-border overflow-hidden">
                                <Table>
                                    <TableHeader className="bg-muted/50">
                                        <TableRow className="border-border hover:bg-transparent">
                                            <TableHead className="w-[80px]">ID</TableHead>
                                            <TableHead>Package Name</TableHead>
                                            <TableHead>Assigned Router</TableHead>
                                            <TableHead>Bandwidth</TableHead>
                                            <TableHead>Tech Profile</TableHead>
                                            <TableHead>Rate Limit</TableHead>
                                            <TableHead>Price</TableHead>
                                            <TableHead>Active Customers</TableHead>
                                            <TableHead className="text-right">Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {filteredPackages.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={9} className="h-32 text-center text-muted-foreground">
                                                    No packages found for selected filter
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            filteredPackages.map((pkg) => (
                                                <TableRow key={pkg.id} className="group hover:bg-muted/50 border-border transition-colors">
                                                    <TableCell className="font-mono text-xs text-muted-foreground font-medium">
                                                        #{pkg.id}
                                                    </TableCell>
                                                    <TableCell className="font-medium text-foreground">
                                                        {pkg.name}
                                                    </TableCell>
                                                    <TableCell>
                                                        {pkg.router ? (
                                                            <Badge variant="outline" className="border-blue-200 text-blue-700 bg-blue-50">
                                                                {pkg.router.name}
                                                            </Badge>
                                                        ) : (
                                                            <Badge variant="outline" className="border-border text-muted-foreground bg-gray-50">
                                                                Global (All)
                                                            </Badge>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge variant="outline" className="border-border text-muted-foreground bg-muted/50">
                                                            {pkg.bandwidth_label}
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell className="font-mono text-xs text-muted-foreground">
                                                        {pkg.mikrotik_profile || '-'}
                                                    </TableCell>
                                                    <TableCell className="font-mono text-xs text-muted-foreground">
                                                        {pkg.rate_limit || '-'}
                                                    </TableCell>
                                                    <TableCell className="font-mono text-emerald-500 font-medium">
                                                        {formatCurrency(pkg.price)}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge variant="secondary" className="bg-muted text-muted-foreground hover:bg-muted/80">
                                                            {pkg.customers_count} {pkg.customers_count === 1 ? 'customer' : 'customers'}
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <div className="flex items-center justify-end gap-2">
                                                            <EditAction
                                                                onClick={() => router.visit(route('packages.edit', pkg.id))}
                                                                title="Edit Package"
                                                            />
                                                            <DeleteAction
                                                                onClick={() => handleDelete(pkg.id)}
                                                                title="Delete Package"
                                                            />
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );

    function handleDelete(id: number) {
        if (confirm('Are you sure you want to delete this package?')) {
            router.delete(route('packages.destroy', id));
        }
    }
}
