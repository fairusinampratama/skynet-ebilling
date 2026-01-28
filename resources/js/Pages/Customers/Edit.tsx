import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { ChevronLeft, Save, User, Network, MapPin, Shield, Trash2 } from 'lucide-react';
import MapPicker from '@/Components/MapPicker';
import { FormEventHandler } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from "@/components/ui/dialog";

// Interfaces
interface Package {
    id: number;
    name: string;
    price: number;
    bandwidth_label: string;
}

interface Customer {
    id: number;
    name: string;
    internal_id?: string;
    address: string;
    phone?: string;
    nik?: string;
    pppoe_user: string;
    package_id: number;
    status: 'active' | 'suspended' | 'isolated' | 'offboarding';
    geo_lat?: string;
    geo_long?: string;
}

interface Props {
    customer: Customer;
    packages: Package[];
}

export default function Edit({ customer, packages }: Props) {
    const { data, setData, put, delete: destroy, processing, errors } = useForm({
        name: customer.name || '',
        internal_id: customer.internal_id || '',
        address: customer.address || '',
        phone: customer.phone || '',
        nik: customer.nik || '',
        pppoe_user: customer.pppoe_user || '',
        pppoe_pass: '', // Default to empty for security (only send if changing)
        package_id: String(customer.package_id),
        status: customer.status,
        geo_lat: customer.geo_lat || '',
        geo_long: customer.geo_long || '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('customers.update', customer.id));
    };

    const handleDelete = () => {
        destroy(route('customers.destroy', customer.id));
    };

    return (
        <AuthenticatedLayout
            breadcrumbs={[
                { label: 'Customers', href: route('customers.index') },
                { label: customer.name, href: route('customers.show', customer.id) },
                { label: 'Edit' }
            ]}
            header={
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-4">
                        <Link href={route('customers.index')}>
                            <Button variant="ghost" size="icon" className="rounded-full">
                                <ChevronLeft className="h-5 w-5" />
                            </Button>
                        </Link>
                        <h2 className="text-xl font-semibold leading-tight text-foreground">
                            Edit Customer: {customer.name}
                        </h2>
                    </div>
                    {/* Delete Action - using Dialog as fallback */}
                    <Dialog>
                        <DialogTrigger asChild>
                            <Button variant="destructive" size="sm">
                                <Trash2 className="h-4 w-4 mr-2" />
                                Delete Customer
                            </Button>
                        </DialogTrigger>
                        <DialogContent className="border-destructive/50">
                            <DialogHeader>
                                <DialogTitle>Are you absolutely sure?</DialogTitle>
                                <DialogDescription>
                                    This action cannot be undone. This will permanently delete the customer
                                    <strong> {customer.name} </strong> and remove their data from our servers.
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter>
                                <Button variant="outline" onClick={() => document.getElementById('close-dialog')?.click()}>Cancel</Button>
                                <Button variant="destructive" onClick={handleDelete} className="bg-destructive text-destructive-foreground hover:bg-destructive/90">
                                    Delete
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>
            }
        >
            <Head title={`Edit ${customer.name}`} />

            <form onSubmit={submit} className="space-y-8 py-6">
                {Object.keys(errors).length > 0 && (
                    <div className="bg-destructive/15 text-destructive p-4 rounded-md border border-destructive/20">
                        <p className="font-semibold">Please fix the following errors:</p>
                        <ul className="list-disc list-inside text-sm mt-1">
                            {Object.entries(errors).map(([field, msg]) => (
                                <li key={field}>{msg}</li>
                            ))}
                        </ul>
                    </div>
                )}
                <div className="grid gap-8 lg:grid-cols-2">
                    {/* Left Column: Personal Information */}
                    <div className="space-y-6">
                        <Card className="border-border bg-card/50 backdrop-blur-sm">
                            <CardHeader>
                                <div className="flex items-center gap-2">
                                    <div className="p-2 bg-primary/10 rounded-lg text-primary">
                                        <User className="h-5 w-5" />
                                    </div>
                                    <div>
                                        <CardTitle>Personal Information</CardTitle>
                                        <CardDescription>Identity and contact details</CardDescription>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid grid-cols-3 gap-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="internal_id">Internal ID</Label>
                                        <Input
                                            id="internal_id"
                                            value={data.internal_id}
                                            onChange={(e) => setData('internal_id', e.target.value)}
                                            placeholder="e.g. 1001"
                                        />
                                        {errors.internal_id && <p className="text-sm text-destructive">{errors.internal_id}</p>}
                                    </div>
                                    <div className="col-span-2 grid gap-2">
                                        <Label htmlFor="name">Full Name <span className="text-red-500">*</span></Label>
                                        <Input
                                            id="name"
                                            value={data.name}
                                            onChange={(e) => setData('name', e.target.value)}
                                            className={errors.name ? 'border-destructive' : ''}
                                            required
                                        />
                                        {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                                    </div>
                                </div>

                                <div className="grid grid-cols-2 gap-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="phone">Phone Number</Label>
                                        <Input
                                            id="phone"
                                            value={data.phone}
                                            onChange={(e) => setData('phone', e.target.value)}
                                        />
                                        {errors.phone && <p className="text-sm text-destructive">{errors.phone}</p>}
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="nik">NIK (Identity)</Label>
                                        <Input
                                            id="nik"
                                            value={data.nik}
                                            onChange={(e) => setData('nik', e.target.value)}
                                        />
                                    </div>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="address">Installation Address <span className="text-red-500">*</span></Label>
                                    <Textarea
                                        id="address"
                                        value={data.address}
                                        onChange={(e) => setData('address', e.target.value)}
                                        className={`min-h-[100px] bg-background/50 ${errors.address ? 'border-destructive' : ''}`}
                                        required
                                    />
                                    {errors.address && <p className="text-sm text-destructive">{errors.address}</p>}
                                </div>
                            </CardContent>
                        </Card>

                        {/* Geo Location */}
                        <Card className="border-border bg-card/50 backdrop-blur-sm">
                            <CardHeader>
                                <div className="flex items-center gap-2">
                                    <div className="p-2 bg-emerald-500/10 rounded-lg text-emerald-500">
                                        <MapPin className="h-5 w-5" />
                                    </div>
                                    <div>
                                        <CardTitle>Geolocation</CardTitle>
                                        <CardDescription>GPS Coordinates for mapping</CardDescription>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="rounded-md overflow-hidden border border-border">
                                    <MapPicker
                                        initialLat={Number(data.geo_lat) || -6.200000}
                                        initialLong={Number(data.geo_long) || 106.816666}
                                        onLocationSelect={(lat: number, lng: number) => {
                                            setData((prev) => ({
                                                ...prev,
                                                geo_lat: lat.toFixed(6),
                                                geo_long: lng.toFixed(6)
                                            }));
                                        }}
                                    />
                                </div>
                                <div className="grid grid-cols-2 gap-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="lat">Latitude</Label>
                                        <Input
                                            id="lat"
                                            value={data.geo_lat}
                                            onChange={(e) => setData('geo_lat', e.target.value)}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="long">Longitude</Label>
                                        <Input
                                            id="long"
                                            value={data.geo_long}
                                            onChange={(e) => setData('geo_long', e.target.value)}
                                        />
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Right Column: Network Information */}
                    <div className="space-y-6">
                        <Card className="border-border bg-card/50 backdrop-blur-sm h-full">
                            <CardHeader>
                                <div className="flex items-center gap-2">
                                    <div className="p-2 bg-blue-500/10 rounded-lg text-blue-500">
                                        <Network className="h-5 w-5" />
                                    </div>
                                    <div>
                                        <CardTitle>Service Configuration</CardTitle>
                                        <CardDescription>Package and Authentication</CardDescription>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-6">
                                <div className="grid gap-2">
                                    <Label htmlFor="package">Subscription Package <span className="text-red-500">*</span></Label>
                                    <Select
                                        value={data.package_id}
                                        onValueChange={(val) => setData('package_id', val)}
                                    >
                                        <SelectTrigger className="bg-background/50">
                                            <SelectValue placeholder="Select a package" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {packages.map((pkg) => (
                                                <SelectItem key={pkg.id} value={String(pkg.id)}>
                                                    <span className="font-medium">{pkg.name}</span>
                                                    <span className="text-muted-foreground ml-2">
                                                        ({pkg.bandwidth_label} - Rp {pkg.price.toLocaleString('id-ID')})
                                                    </span>
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.package_id && <p className="text-sm text-destructive">{errors.package_id}</p>}
                                </div>

                                {/* Status modification removed to enforce use of Toggle on Details page */}

                                <div className="border-t border-border/50 my-6"></div>

                                <div className="space-y-4">
                                    <div className="flex items-center gap-2 mb-4">
                                        <Shield className="h-4 w-4 text-orange-500" />
                                        <span className="font-medium text-sm text-foreground">PPPoE Credentials</span>
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="pppoe_user">Username</Label>
                                        <Input
                                            id="pppoe_user"
                                            value={data.pppoe_user}
                                            // PPPoE User is usually less editable to track history, but allowing edit here
                                            onChange={(e) => setData('pppoe_user', e.target.value)}
                                            className="font-mono bg-muted/30"
                                        />
                                        {errors.pppoe_user && <p className="text-sm text-destructive">{errors.pppoe_user}</p>}
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="pppoe_pass">
                                            New Password <span className="text-muted-foreground font-normal">(Optional)</span>
                                        </Label>
                                        <Input
                                            id="pppoe_pass"
                                            type="text"
                                            value={data.pppoe_pass}
                                            onChange={(e) => setData('pppoe_pass', e.target.value)}
                                            placeholder="Leave blank to keep current password"
                                            className="font-mono"
                                        />
                                        <p className="text-[10px] text-muted-foreground">
                                            Enter a new value only if you want to change the customer's PPPoE password.
                                        </p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>

                <div className="flex justify-end gap-4 mt-8">
                    <Link href={route('customers.index')}>
                        <Button variant="outline" type="button">Cancel</Button>
                    </Link>
                    <Button type="submit" disabled={processing} className="min-w-[150px]">
                        {processing ? 'Saving...' : 'Save Changes'}
                        {!processing && <Save className="ml-2 h-4 w-4" />}
                    </Button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
