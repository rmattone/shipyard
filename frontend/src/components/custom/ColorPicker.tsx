import { cn } from "@/lib/utils"
import { TAG_COLORS, TagColor } from "@/services/api"
import { Check } from "lucide-react"

interface ColorPickerProps {
  value: TagColor
  onChange: (color: TagColor) => void
}

const colorBgClasses: Record<TagColor, string> = {
  gray: "bg-gray-400",
  red: "bg-red-400",
  orange: "bg-orange-400",
  amber: "bg-amber-400",
  yellow: "bg-yellow-400",
  lime: "bg-lime-400",
  green: "bg-green-400",
  emerald: "bg-emerald-400",
  teal: "bg-teal-400",
  cyan: "bg-cyan-400",
  blue: "bg-blue-400",
  indigo: "bg-indigo-400",
  violet: "bg-violet-400",
  purple: "bg-purple-400",
  pink: "bg-pink-400",
  rose: "bg-rose-400",
}

export function ColorPicker({ value, onChange }: ColorPickerProps) {
  return (
    <div className="grid grid-cols-8 gap-2">
      {TAG_COLORS.map((color) => (
        <button
          key={color}
          type="button"
          onClick={() => onChange(color)}
          className={cn(
            "w-6 h-6 rounded-full flex items-center justify-center transition-transform hover:scale-110",
            colorBgClasses[color],
            value === color && "ring-2 ring-offset-2 ring-gray-400"
          )}
          title={color}
        >
          {value === color && (
            <Check className="h-3 w-3 text-white" />
          )}
        </button>
      ))}
    </div>
  )
}
