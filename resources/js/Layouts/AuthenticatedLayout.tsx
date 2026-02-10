import { PropsWithChildren, ReactNode, useState, useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import { PageProps } from '@/types';
import {
    Menu,
    Search,
    Bell,
    LogOut,
    User,
    X
} from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Link } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import { ThemeToggle } from '@/Components/ThemeToggle';
import { AppSidebar } from '@/Components/AppSidebar';
import Breadcrumbs, { BreadcrumbItem } from '@/Components/Common/Breadcrumbs';

import { Toaster } from '@/Components/ui/sonner';
import { toast } from 'sonner';

export default function Authenticated({
    header,
    children,
    breadcrumbs,
}: PropsWithChildren<{ header?: ReactNode; breadcrumbs?: BreadcrumbItem[] }>) {
    const user = usePage().props.auth.user;
    const { flash } = usePage<PageProps>().props; // Clean typescript inference
    const [showingNavigationDropdown, setShowingNavigationDropdown] = useState(false);

    // Watch for flash messages
    useEffect(() => {
        if (flash.success) {
            toast.success(flash.success);
        }
        if (flash.error) {
            toast.error(flash.error);
        }
    }, [flash]);

    return (
        <div className="flex h-screen bg-background font-sans overflow-hidden">
            <Toaster />
            {/* Background Pattern - Subtle Texture */}
            <div className="fixed inset-0 z-0 pointer-events-none opacity-50">
                <div className="absolute inset-0"
                    style={{
                        backgroundImage: `radial-gradient(circle at 2px 2px, var(--color-border) 1px, transparent 0)`,
                        backgroundSize: '40px 40px'
                    }}>
                </div>
            </div>

            {/* Sidebar (Desktop) - Extracted Component */}
            <AppSidebar />

            {/* Mobile Nav Overlay */}
            {showingNavigationDropdown && (
                <div className="fixed inset-0 z-50 bg-background/80 backdrop-blur-sm md:hidden">
                    <div className="fixed inset-y-0 left-0 z-50 h-full w-3/4 gap-4 border-r border-border bg-background p-6 shadow-lg sm:max-w-sm">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2 font-semibold">
                                <ApplicationLogo className="h-6 w-6 fill-current text-primary" />
                                <span className="text-lg">Skynet</span>
                            </div>
                            <Button
                                variant="ghost"
                                size="icon"
                                onClick={() => setShowingNavigationDropdown(false)}
                            >
                                <X className="h-5 w-5" />
                            </Button>
                        </div>
                        {/* Mobile Nav Links - Simplified for now, can extract later if needed */}
                        <div className="mt-8 space-y-4">
                            <p className="text-muted-foreground text-sm">Navigation</p>
                            {/* Reusing Sidebar Logic here would be ideal, but for now keeping it simple or duplicating logic */}
                            {/* ... (Mobile links implementation - skipping for brevity to focus on Desktop Layout) ... */}
                            <div className="text-sm text-muted-foreground italic">Mobile menu logic here</div>
                        </div>
                    </div>
                </div>
            )}

            {/* Main Content Area */}
            <div className="flex flex-1 flex-col overflow-hidden relative z-10 w-full md:w-[calc(100%-16rem)]">
                {/* Floating Glass Header */}
                <header className="mx-4 mt-4 mb-0 flex h-16 items-center gap-4 rounded-2xl border border-border/50 bg-background/60 backdrop-blur-md px-6 z-20 shadow-sm transition-all hover:shadow-md hover:border-border/80">
                    <Button
                        variant="ghost"
                        size="icon"
                        className="shrink-0 md:hidden"
                        onClick={() => setShowingNavigationDropdown(true)}
                    >
                        <Menu className="h-5 w-5" />
                        <span className="sr-only">Toggle navigation menu</span>
                    </Button>

                    <div className="flex flex-1 items-center gap-4">
                        <div className="hidden md:block">
                            <Breadcrumbs items={breadcrumbs} />
                        </div>

                        {/* Divider */}
                        <div className="hidden md:block h-4 w-px bg-border/50"></div>

                        {/* Search Input (Cmd+K Trigger Placeholder) */}
                        <form className="w-full sm:ml-auto md:w-auto lg:w-96 opacity-0 pointer-events-none md:opacity-100 md:pointer-events-auto transition-opacity">
                            <div className="relative group">
                                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground group-hover:text-primary transition-colors" />
                                <Input
                                    type="search"
                                    placeholder="Search ..."
                                    className="w-full appearance-none bg-muted/50 pl-9 shadow-none md:w-full lg:w-96 rounded-xl focus-visible:ring-1 focus-visible:ring-primary focus-visible:border-primary border-transparent hover:bg-muted transition-all"
                                />
                            </div>
                        </form>
                    </div>

                    {/* Header Right: Actions */}
                    <div className="flex items-center gap-2">
                        <ThemeToggle />

                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="ghost" size="icon" className="rounded-full">
                                    <Bell className="h-5 w-5 text-muted-foreground" />
                                    <span className="sr-only">Notifications</span>
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuLabel>Notifications</DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem>No new notifications</DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>

                        {/* Mobile User Menu Trigger (Desktop has it in sidebar) */}
                        <div className="md:hidden">
                            {/* Mobile User Menu Logic */}
                        </div>
                    </div>
                </header>

                {/* Main Content Scroll Area */}
                <main className="flex-1 overflow-y-auto p-4 lg:p-6 scroll-smooth">
                    {/* Page Header (Optional) */}
                    {header && (
                        <div className="mb-6 px-2">
                            <h1 className="text-2xl font-bold tracking-tight text-foreground">
                                {header}
                            </h1>
                        </div>
                    )}

                    {/* Page Content */}
                    <div className="mx-auto max-w-7xl animate-in fade-in slide-in-from-bottom-4 duration-500 pb-10">
                        {children}
                    </div>
                </main>
            </div>
        </div>
    );
}
