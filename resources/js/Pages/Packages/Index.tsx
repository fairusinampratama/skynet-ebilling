import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface Package {
    id: number;
    name: string;
    price: number;
    bandwidth_label: string;
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
                    <Card className="border-border bg-card/50 backdrop-blur-sm shadow-none">
                        <CardHeader>
                            <CardTitle>Package Management</CardTitle>
                            <CardDescription>
                                {packages.length} package(s) available
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="rounded-md border border-border overflow-hidden">
                                <Table>
                                    <TableHeader className="bg-muted/50">
                                        <TableRow className="border-border hover:bg-transparent">
                                            <TableHead className="w-[100px]">ID</TableHead>
                                            <TableHead>Package Name</TableHead>
                                            <TableHead>Bandwidth</TableHead>
                                            <TableHead>Price</TableHead>
                                            <TableHead>Active Customers</TableHead>
                                            <TableHead className="text-right">Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {packages.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={6} className="h-32 text-center text-muted-foreground">
                                                    No packages found
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            packages.map((pkg) => (
                                                <TableRow key={pkg.id} className="group hover:bg-muted/50 border-border transition-colors">
                                                    <TableCell className="font-mono text-xs text-muted-foreground font-medium">
                                                        #{pkg.id}
                                                    </TableCell>
                                                    <TableCell className="font-medium text-foreground">
                                                        {pkg.name}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge variant="outline" className="border-border text-muted-foreground bg-muted/50">
                                                            {pkg.bandwidth_label}
                                                        </Badge>
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
                                                            <Link href={`/packages/${pkg.id}/edit`}>
                                                                <Button variant="ghost" size="sm" className="h-8 w-8 p-0 text-muted-foreground hover:text-foreground">
                                                                    <span className="sr-only">Edit</span>
                                                                    <svg width="15" height="15" viewBox="0 0 15 15" fill="none" xmlns="http://www.w3.org/2000/svg" className="h-4 w-4"><path d="M11.8536 1.14645C11.6583 0.951184 11.3417 0.951184 11.1465 1.14645L3.71455 8.57836C3.62459 8.66832 3.55263 8.77461 3.50251 8.89155L2.04044 12.303C1.9599 12.491 2.00178 12.709 2.14646 12.8536C2.29115 12.9982 2.50905 13.0401 2.69697 12.9596L6.10847 11.4975C6.2254 11.4474 6.3317 11.3754 6.42166 11.2855L13.8536 3.85355C14.0488 3.65829 14.0488 3.34171 13.8536 3.14645L11.8536 1.14645Z" fill="currentColor" fillRule="evenodd" clipRule="evenodd"></path></svg>
                                                                </Button>
                                                            </Link>
                                                            <Link href={`/packages/${pkg.id}`}>
                                                                <Button variant="ghost" size="sm" className="h-8 w-8 p-0 text-muted-foreground hover:text-foreground">
                                                                    <span className="sr-only">View</span>
                                                                    <svg width="15" height="15" viewBox="0 0 15 15" fill="none" xmlns="http://www.w3.org/2000/svg" className="h-4 w-4"><path d="M3 7.5C3 7.22386 3.22386 7 3.5 7H11.5C11.7761 7 12 7.22386 12 7.5C12 7.77614 11.7761 8 11.5 8H3.5C3.22386 8 3 7.77614 3 7.5Z" fill="currentColor" fillRule="evenodd" clipRule="evenodd"></path></svg>
                                                                </Button>
                                                            </Link>
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
}
