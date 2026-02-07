import { Badge } from "@/components/ui/badge"
import { cn } from "@/lib/utils"

type StatusType = 'active' | 'inactive' | 'deploying' | 'running' | 'pending' | 'failed' | 'success' | string

interface StatusBadgeProps {
  status: StatusType
  className?: string
}

const statusStyles: Record<string, string> = {
  active: "bg-green-100 text-green-800 hover:bg-green-100",
  success: "bg-green-100 text-green-800 hover:bg-green-100",
  inactive: "bg-gray-100 text-gray-800 hover:bg-gray-100",
  deploying: "bg-yellow-100 text-yellow-800 hover:bg-yellow-100",
  running: "bg-yellow-100 text-yellow-800 hover:bg-yellow-100",
  pending: "bg-yellow-100 text-yellow-800 hover:bg-yellow-100",
  failed: "bg-red-100 text-red-800 hover:bg-red-100",
}

export function StatusBadge({ status, className }: StatusBadgeProps) {
  const styles = statusStyles[status] || statusStyles.inactive

  return (
    <Badge
      variant="secondary"
      className={cn(styles, className)}
    >
      {status}
    </Badge>
  )
}
