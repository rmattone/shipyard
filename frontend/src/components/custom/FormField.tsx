import { Label } from "@/components/ui/label"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import { cn } from "@/lib/utils"
import { forwardRef, InputHTMLAttributes, TextareaHTMLAttributes } from "react"

interface BaseFormFieldProps {
  label?: string
  error?: string
  className?: string
}

interface InputFormFieldProps extends BaseFormFieldProps, Omit<InputHTMLAttributes<HTMLInputElement>, 'className'> {
  as?: "input"
}

interface TextareaFormFieldProps extends BaseFormFieldProps, Omit<TextareaHTMLAttributes<HTMLTextAreaElement>, 'className'> {
  as: "textarea"
}

type FormFieldProps = InputFormFieldProps | TextareaFormFieldProps

export const FormField = forwardRef<HTMLInputElement | HTMLTextAreaElement, FormFieldProps>(
  ({ label, error, className, as = "input", ...props }, ref) => {
    const id = props.id || props.name

    return (
      <div className={cn("space-y-2", className)}>
        {label && (
          <Label htmlFor={id} className={error ? "text-destructive" : undefined}>
            {label}
          </Label>
        )}
        {as === "textarea" ? (
          <Textarea
            id={id}
            ref={ref as React.Ref<HTMLTextAreaElement>}
            className={cn(error && "border-destructive focus-visible:ring-destructive")}
            {...(props as TextareaHTMLAttributes<HTMLTextAreaElement>)}
          />
        ) : (
          <Input
            id={id}
            ref={ref as React.Ref<HTMLInputElement>}
            className={cn(error && "border-destructive focus-visible:ring-destructive")}
            {...(props as InputHTMLAttributes<HTMLInputElement>)}
          />
        )}
        {error && <p className="text-sm text-destructive">{error}</p>}
      </div>
    )
  }
)

FormField.displayName = "FormField"
