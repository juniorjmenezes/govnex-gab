"use client"

import * as React from "react"
import { Progress as ProgressPrimitive } from "@base-ui/react/progress"

import { cn } from "@/lib/utils"

// Cor única das barras: #2b2422 = oklch(0.268 0.011 36.5); no escuro,
// #d8d2d0 (--chart-1 do tema escuro), para não sumir no card. Quem precisa
// de outra cor (candidato favorito) sobrescreve por className.
function Progress({
  className,
  indicatorClassName,
  render,
  trackRender,
  indicatorRender,
  ...props
}: React.ComponentProps<typeof ProgressPrimitive.Root> & {
  indicatorClassName?: string
  trackRender?: React.ComponentProps<typeof ProgressPrimitive.Track>["render"]
  indicatorRender?: React.ComponentProps<
    typeof ProgressPrimitive.Indicator
  >["render"]
}) {
  return (
    <ProgressPrimitive.Root
      data-slot="progress"
      render={render}
      className="relative"
      {...props}
    >
      <ProgressPrimitive.Track
        data-slot="progress-track"
        render={trackRender}
        className={cn(
          "relative h-1 w-full overflow-hidden rounded-full bg-[#2b2422]/20 dark:bg-[#d8d2d0]/20",
          className
        )}
      >
        <ProgressPrimitive.Indicator
          data-slot="progress-indicator"
          render={indicatorRender}
          className={cn(
            "h-full bg-[#2b2422] dark:bg-[#d8d2d0] transition-[width] duration-500 ease-out data-[indeterminate]:w-full data-[indeterminate]:animate-pulse",
            indicatorClassName
          )}
        />
      </ProgressPrimitive.Track>
    </ProgressPrimitive.Root>
  )
}

export { Progress }
