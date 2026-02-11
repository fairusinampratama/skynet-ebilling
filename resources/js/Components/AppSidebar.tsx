import { Link, usePage } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import { LayoutDashboard, Users, FileText, Package, Server, Settings, MapPin, BarChart3, MoreVertical, LogOut } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';

export function AppSidebar() {
    const { url } = usePage(); // Use usePage hook to get current URL

    const navItems = [
        { name: 'Dashboard', route: 'dashboard', icon: LayoutDashboard },
        { name: 'Analytics', route: 'analytics.index', icon: BarChart3 },
        { name: 'Customers', route: 'customers.index', icon: Users },
        { name: 'Invoices', route: 'invoices.index', icon: FileText },
        { name: 'Packages', route: 'packages.index', icon: Package },
        { name: 'Areas', route: 'areas.index', icon: MapPin },
        { name: 'Settings', route: 'settings.index', icon: Settings },
    ];

    return (
        <aside className="hidden w-64 flex-col border-r border-border bg-card text-card-foreground md:flex shadow-sm z-30">
            <div className="flex h-16 items-center border-b border-border px-6 bg-card">
                <Link href="/" className="flex items-center gap-2 font-semibold">
                    <ApplicationLogo className="h-6 w-6 fill-current text-primary" />
                    <span className="text-lg tracking-tight">Skynet Admin</span>
                </Link>
            </div>
            <div className="flex-1 overflow-auto py-4 bg-card">
                <nav className="grid items-start px-4 text-sm font-medium space-y-1">
                    {navItems.map((item) => {
                        // Check active state
                        const isActive = route().current(item.route.replace('.index', '') + '*');

                        return (
                            <Link
                                key={item.route}
                                href={route(item.route)}
                                className={`flex items-center gap-3 rounded-lg px-3 py-2.5 transition-all outline-none relative group ${isActive
                                    ? 'text-primary font-semibold'
                                    : 'text-muted-foreground hover:text-foreground hover:bg-muted/50'
                                    }`}
                            >
                                {/* Active State "Luminescent" Background */}
                                {isActive && (
                                    <div className="absolute inset-0 bg-primary/10 rounded-lg border-l-2 border-primary" />
                                )}

                                <item.icon className={`h-4 w-4 relative z-10 ${isActive ? 'text-primary' : 'text-muted-foreground group-hover:text-foreground'}`} />
                                <span className="relative z-10">{item.name}</span>
                            </Link>
                        );
                    })}
                </nav>
            </div>
            {/* User Footer Section */}
            <div className="border-t border-border p-4 bg-card">
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <button className="flex w-full items-center gap-3 rounded-lg border border-border p-3 bg-muted/20 hover:bg-muted/40 transition-colors text-left outline-none">
                            <div className="h-9 w-9 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold text-xs">
                                U
                            </div>
                            <div className="flex-1 overflow-hidden">
                                <p className="truncate text-sm font-medium">Admin</p>
                                <p className="truncate text-xs text-muted-foreground">admin@skynet.id</p>
                            </div>
                            <MoreVertical className="h-4 w-4 text-muted-foreground" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent side="top" align="start" className="w-56 mb-2">
                        <DropdownMenuLabel>My Account</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem asChild>
                            <Link href={route('logout')} method="post" as="button" className="w-full cursor-pointer flex items-center gap-2 text-destructive focus:text-destructive">
                                <LogOut className="h-4 w-4" />
                                <span>Log Out</span>
                            </Link>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </aside>
    );
}
