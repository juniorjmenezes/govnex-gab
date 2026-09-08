import { Card, CardDescription } from '@/components/ui/card';
import type { AuthLayoutProps } from '@/types';

export default function AuthCardLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-muted p-6 md:p-10">
            <div className="flex w-full max-w-sm flex-col gap-6">
                <Card className="gap-0 py-0">
                    <div className="flex flex-col gap-1 border-b p-4 text-center">
                        <h1 className="text-xl font-semibold tracking-tight">
                            {title}
                        </h1>
                        <CardDescription>{description}</CardDescription>
                    </div>
                    <div className="p-5">{children}</div>
                </Card>
            </div>
        </div>
    );
}
