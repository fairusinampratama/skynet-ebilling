import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react'; // Cleaned imports
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea'; // Assuming you have this
import { ChevronLeft, Save, User, Network, MapPin, Shield } from 'lucide-react';
import MapPicker from '@/Components/MapPicker';
import { FormEventHandler } from 'react';

// Interfaces
interface Package {
    id: number;
    name: string;
    price: number;
    bandwidth_label: string;
}

interface Props {
    packages: Package[];
}

export default function Create({ packages }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        internal_id: '', // Optional, maybe auto-gen?
        address: '',
        phone: '',
        nik: '',
        pppoe_user: '',
        pppoe_pass: '',
        package_id: '',
        status: 'active',
        geo_lat: '',
        geo_long: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('customers.store'));
    };

    // Auto-generate PPPoE user based on name (Optional helper)
    const handleNameChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const val = e.target.value;
        setData('name', val);
        // Simple auto-slug for pppoe if empty
        if (!data.pppoe_user) {
            setData((data) => ({
                ...data,
                name: val,
                pppoe_user: val.toLowerCase().replace(/\s+/g, '')
            }));
        } else {
            setData('name', val);
        }
    };

    return (
        <AuthenticatedLayout
            breadcrumbs={[
                { label: 'Customers', href: route('customers.index') },
                { label: 'Create' }
            ]}
            header={
                <div className="flex items-center gap-4">
                    <Link href={route('customers.index')}>
                        <Button variant="ghost" size="icon" className="rounded-full">
                            <ChevronLeft className="h-5 w-5" />
                        </Button>
                    </Link>
                    <h2 className="text-xl font-semibold leading-tight text-foreground">
                        Register New Customer
                    </h2>
                </div>
            }
        >
            <Head title="Create Customer" />

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
                                            onChange={handleNameChange}
                                            placeholder="e.g. John Doe"
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
                                            placeholder="0812..."
                                        />
                                        {errors.phone && <p className="text-sm text-destructive">{errors.phone}</p>}
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="nik">NIK (Identity)</Label>
                                        <Input
                                            id="nik"
                                            value={data.nik}
                                            onChange={(e) => setData('nik', e.target.value)}
                                            placeholder="16-digit ID"
                                        />
                                    </div>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="address">Installation Address <span className="text-red-500">*</span></Label>
                                    <Textarea
                                        id="address"
                                        value={data.address}
                                        onChange={(e) => setData('address', e.target.value)}
                                        placeholder="Full street address..."
                                        className={`min-h-[100px] bg-background/50 ${errors.address ? 'border-destructive' : ''}`}
                                        required
                                    />
                                    {errors.address && <p className="text-sm text-destructive">{errors.address}</p>}
                                </div>
                            </CardContent>
                        </Card>

                        {/* Geo Location (Simplified) */}
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
                                            placeholder="-6.200000"
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="long">Longitude</Label>
                                        <Input
                                            id="long"
                                            value={data.geo_long}
                                            onChange={(e) => setData('geo_long', e.target.value)}
                                            placeholder="106.816666"
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

                                <div className="grid gap-2">
                                    <Label htmlFor="status">Account Status</Label>
                                    <Select
                                        value={data.status}
                                        onValueChange={(val) => setData('status', val)}
                                    >
                                        <SelectTrigger className="bg-background/50">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="active">Active</SelectItem>
                                            <SelectItem value="suspended">Suspended</SelectItem>
                                            <SelectItem value="isolated">Isolated</SelectItem>
                                            <SelectItem value="offboarding">Offboarding</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="border-t border-border/50 my-6"></div>

                                <div className="space-y-4">
                                    <div className="flex items-center gap-2 mb-4">
                                        <Shield className="h-4 w-4 text-orange-500" />
                                        <span className="font-medium text-sm text-foreground">PPPoE Credentials</span>
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="pppoe_user">Username <span className="text-red-500">*</span></Label>
                                        <Input
                                            id="pppoe_user"
                                            value={data.pppoe_user}
                                            onChange={(e) => setData('pppoe_user', e.target.value)}
                                            placeholder="john.doe"
                                            className="font-mono"
                                            required
                                        />
                                        {errors.pppoe_user && <p className="text-sm text-destructive">{errors.pppoe_user}</p>}
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="pppoe_pass">Password <span className="text-red-500">*</span></Label>
                                        <Input
                                            id="pppoe_pass"
                                            type="text" // Visible for admin creation convenience usually
                                            value={data.pppoe_pass}
                                            onChange={(e) => setData('pppoe_pass', e.target.value)}
                                            placeholder="Secret123"
                                            className="font-mono"
                                            required
                                        />
                                        {errors.pppoe_pass && <p className="text-sm text-destructive">{errors.pppoe_pass}</p>}
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
                        {processing ? 'Saving...' : 'Create Customer'}
                        {!processing && <Save className="ml-2 h-4 w-4" />}
                    </Button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
