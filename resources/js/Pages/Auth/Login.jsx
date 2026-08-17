import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';

export default function Login({ status, canResetPassword }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Sign In" />

            <div className="mb-6">
                <h2 className="text-2xl font-bold text-[#2D3748]">Sign In</h2>
                <p className="text-sm text-[#718096] mt-1">
                    Access your GRC dashboard
                </p>
            </div>

            {status && (
                <div className="mb-4 text-sm font-medium text-[#2D7D46] bg-[#2D7D46]/10 px-4 py-2 rounded-lg">
                    {status}
                </div>
            )}

            <form onSubmit={submit}>
                <div>
                    <InputLabel htmlFor="email" value="Email Address" />
                    <TextInput
                        id="email"
                        type="email"
                        name="email"
                        value={data.email}
                        className="mt-1 block w-full"
                        autoComplete="username"
                        isFocused={true}
                        onChange={(e) => setData('email', e.target.value)}
                    />
                    <InputError message={errors.email} className="mt-2" />
                </div>

                <div className="mt-4">
                    <InputLabel htmlFor="password" value="Password" />
                    <TextInput
                        id="password"
                        type="password"
                        name="password"
                        value={data.password}
                        className="mt-1 block w-full"
                        autoComplete="current-password"
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <InputError message={errors.password} className="mt-2" />
                </div>

                <div className="mt-4 flex items-center justify-between">
                    <label className="flex items-center">
                        <Checkbox
                            name="remember"
                            checked={data.remember}
                            onChange={(e) => setData('remember', e.target.checked)}
                        />
                        <span className="ms-2 text-sm text-[#718096]">Remember me</span>
                    </label>

                    {canResetPassword && (
                        <Link
                            href={route('password.request')}
                            className="text-sm text-[#1A365D] hover:text-[#D4AF37] font-medium transition-colors"
                        >
                            Forgot password?
                        </Link>
                    )}
                </div>

                <PrimaryButton className="w-full justify-center mt-6" disabled={processing}>
                    Sign In
                </PrimaryButton>

                <p className="mt-4 text-center text-sm text-[#718096]">
                    Don't have an account?{' '}
                    <Link href={route('register')} className="text-[#1A365D] hover:text-[#D4AF37] font-medium transition-colors">
                        Create one
                    </Link>
                </p>
            </form>
        </GuestLayout>
    );
}
