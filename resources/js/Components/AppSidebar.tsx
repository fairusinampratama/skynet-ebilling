import { Link, usePage } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import { LayoutDashboard, Users, FileText, Package, Server, Settings } from 'lucide-react';

export function AppSidebar() {
    const { url } = usePage(); // Use usePage hook to get current URL

    const navItems = [
        { name: 'Dashboard', route: 'dashboard', icon: LayoutDashboard },
        { name: 'Customers', route: 'customers.index', icon: Users },
        { name: 'Invoices', route: 'invoices.index', icon: FileText },
        { name: 'Packages', route: 'packages.index', icon: Package },
        { name: 'Routers', route: 'routers.index', icon: Server },
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
                <div className="flex items-center gap-3 rounded-lg border border-border p-3 bg-muted/20">
                    <div className="h-9 w-9 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold text-xs">
                        {/* We need user from page props */}
                        U
                    </div>
                    <div className="flex-1 overflow-hidden">
                        <p className="truncate text-sm font-medium">Admin</p>
                        <p className="truncate text-xs text-muted-foreground">admin@skynet.id</p>
                    </div>
                </div>
            </div>
        </aside>
    );
}
