import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Badge } from '@/Components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/Components/ui/dropdown-menu';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import { MoreHorizontal, Plus, Search, Trash2, Edit } from 'lucide-react';
import { useState } from 'react';

interface Area {
    id: number;
    name: string;
    code: string;
}

interface ManagedUser {
    id: number;
    name: string;
    email: string;
    role: 'superadmin' | 'admin';
    areas: Area[];
    created_at: string;
}

interface Props {
    users: {
        data: ManagedUser[];
        links: any[];
        current_page: number;
        last_page: number;
    };
    filters: {
        search?: string;
    };
}

export default function Index({ users, filters }: Props) {
    const [search, setSearch] = useState(filters.search || '');
    const [userToDelete, setUserToDelete] = useState<ManagedUser | null>(null);

    const submitSearch = (event: React.FormEvent) => {
        event.preventDefault();
        router.get(route('users.index'), { search }, { preserveState: true });
    };

    return (
        <AuthenticatedLayout
            breadcrumbs={[{ label: 'Users', href: route('users.index') }]}
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-foreground">Users</h2>
                    <Button asChild>
                        <Link href={route('users.create')}>
                            <Plus className="mr-2 h-4 w-4" />
                            Add User
                        </Link>
                    </Button>
                </div>
            }
        >
            <Head title="Users" />

            <div className="py-8 space-y-4">
                <div className="flex items-center justify-between bg-card p-4 rounded-lg border shadow-sm">
                    <form onSubmit={submitSearch} className="flex w-full max-w-sm items-center space-x-2">
                        <div className="relative flex-1">
                            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                            <Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search users..." className="pl-8" />
                        </div>
                    </form>
                </div>

                <div className="rounded-md border bg-card shadow-sm">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Email</TableHead>
                                <TableHead>Role</TableHead>
                                <TableHead>Scope</TableHead>
                                <TableHead className="w-[80px]" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {users.data.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={5} className="h-24 text-center text-muted-foreground">No users found.</TableCell>
                                </TableRow>
                            ) : users.data.map((user) => (
                                <TableRow key={user.id}>
                                    <TableCell className="font-medium">{user.name}</TableCell>
                                    <TableCell>{user.email}</TableCell>
                                    <TableCell>
                                        <Badge variant={user.role === 'superadmin' ? 'default' : 'secondary'}>
                                            {user.role === 'superadmin' ? 'Superadmin' : 'Admin'}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="max-w-md">
                                        {user.role === 'superadmin'
                                            ? 'All access'
                                            : user.areas.length === 0
                                                ? 'Global admin'
                                                : user.areas.map((area) => area.name).join(', ')}
                                    </TableCell>
                                    <TableCell>
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button variant="ghost" className="h-8 w-8 p-0">
                                                    <MoreHorizontal className="h-4 w-4" />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuItem onClick={() => router.visit(route('users.edit', user.id))}>
                                                    <Edit className="mr-2 h-4 w-4" />
                                                    Edit
                                                </DropdownMenuItem>
                                                <DropdownMenuItem onClick={() => setUserToDelete(user)} className="text-destructive focus:text-destructive">
                                                    <Trash2 className="mr-2 h-4 w-4" />
                                                    Delete
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>

            <ConfirmDialog
                open={!!userToDelete}
                onOpenChange={(open) => !open && setUserToDelete(null)}
                title="Delete User?"
                description={`Delete ${userToDelete?.name}? This cannot be undone.`}
                confirmText="Delete"
                variant="destructive"
                onConfirm={() => userToDelete && router.delete(route('users.destroy', userToDelete.id), { onFinish: () => setUserToDelete(null) })}
            />
        </AuthenticatedLayout>
    );
}
