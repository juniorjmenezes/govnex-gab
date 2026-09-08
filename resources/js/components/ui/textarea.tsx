import * as React from "react"

import { cn } from "@/lib/utils"

function Textarea({ className, ...props }: React.ComponentProps<"textarea">) {
  return (
    <textarea
      data-slot="textarea"
      className={cn(
        "flex field-sizing-content min-h-16 w-full resize-none rounded-md border border-input bg-muted px-3 py-3 text-base transition-[color,border-color,box-shadow] outline-none placeholder:text-muted-foreground hover:border-[color-mix(in_oklch,var(--input),var(--foreground)_12%)] focus-visible:border-[color-mix(in_oklch,var(--input),var(--foreground)_25%)] disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-2 aria-invalid:ring-destructive/20 md:text-sm dark:aria-invalid:border-destructive/50 dark:aria-invalid:ring-destructive/40",
        className
      )}
      {...props}
    />
  )
}

export { Textarea }
