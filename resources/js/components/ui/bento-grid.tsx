import * as React from "react"

import { cn } from "@/lib/utils"

function BentoGrid({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="bento-grid"
      className={cn(
        "grid gap-4 md:grid-cols-2 lg:grid-cols-3",
        className
      )}
      {...props}
    />
  )
}

function BentoGridItem({ className, ...props }: React.ComponentProps<"section">) {
  return (
    <section
      data-slot="bento-grid-item"
      className={cn(
        "self-start flex flex-col gap-4 rounded-lg border bg-card p-4 text-card-foreground shadow-sm",
        className
      )}
      {...props}
    />
  )
}

export { BentoGrid, BentoGridItem }
