import { cn } from "@/lib/utils"
import { ReactNode } from "react"

interface DefinitionItemProps {
  term: string
  children: ReactNode
  className?: string
}

export function DefinitionItem({ term, children, className }: DefinitionItemProps) {
  return (
    <div className={cn("py-4 sm:grid sm:grid-cols-3 sm:gap-4", className)}>
      <dt className="text-sm font-medium text-muted-foreground">{term}</dt>
      <dd className="mt-1 text-sm sm:col-span-2 sm:mt-0">{children}</dd>
    </div>
  )
}

interface DefinitionListProps {
  children: ReactNode
  className?: string
}

export function DefinitionList({ children, className }: DefinitionListProps) {
  return (
    <dl className={cn("divide-y divide-border", className)}>
      {children}
    </dl>
  )
}
