import { Badge } from "@/components/ui/badge"
import { cn } from "@/lib/utils"
import { Tag, TagColor } from "@/services/api"
import { X } from "lucide-react"

interface TagBadgeProps {
  tag: Tag
  onClick?: () => void
  onRemove?: () => void
  selected?: boolean
  className?: string
}

const colorStyles: Record<TagColor, { bg: string; text: string; ring: string }> = {
  gray: { bg: "bg-gray-100", text: "text-gray-700", ring: "ring-gray-400" },
  red: { bg: "bg-red-100", text: "text-red-700", ring: "ring-red-400" },
  orange: { bg: "bg-orange-100", text: "text-orange-700", ring: "ring-orange-400" },
  amber: { bg: "bg-amber-100", text: "text-amber-700", ring: "ring-amber-400" },
  yellow: { bg: "bg-yellow-100", text: "text-yellow-700", ring: "ring-yellow-400" },
  lime: { bg: "bg-lime-100", text: "text-lime-700", ring: "ring-lime-400" },
  green: { bg: "bg-green-100", text: "text-green-700", ring: "ring-green-400" },
  emerald: { bg: "bg-emerald-100", text: "text-emerald-700", ring: "ring-emerald-400" },
  teal: { bg: "bg-teal-100", text: "text-teal-700", ring: "ring-teal-400" },
  cyan: { bg: "bg-cyan-100", text: "text-cyan-700", ring: "ring-cyan-400" },
  blue: { bg: "bg-blue-100", text: "text-blue-700", ring: "ring-blue-400" },
  indigo: { bg: "bg-indigo-100", text: "text-indigo-700", ring: "ring-indigo-400" },
  violet: { bg: "bg-violet-100", text: "text-violet-700", ring: "ring-violet-400" },
  purple: { bg: "bg-purple-100", text: "text-purple-700", ring: "ring-purple-400" },
  pink: { bg: "bg-pink-100", text: "text-pink-700", ring: "ring-pink-400" },
  rose: { bg: "bg-rose-100", text: "text-rose-700", ring: "ring-rose-400" },
}

export function TagBadge({ tag, onClick, onRemove, selected, className }: TagBadgeProps) {
  const color = (tag.color as TagColor) || 'gray'
  const styles = colorStyles[color] || colorStyles.gray

  return (
    <Badge
      variant="secondary"
      className={cn(
        styles.bg,
        styles.text,
        "hover:opacity-80",
        onClick && "cursor-pointer",
        selected && `ring-2 ${styles.ring}`,
        onRemove && "pr-1",
        className
      )}
      onClick={onClick}
    >
      {tag.name}
      {onRemove && (
        <button
          type="button"
          onClick={(e) => {
            e.stopPropagation()
            onRemove()
          }}
          className="ml-1 hover:bg-black/10 rounded-full p-0.5"
        >
          <X className="h-3 w-3" />
        </button>
      )}
    </Badge>
  )
}

export { colorStyles }
