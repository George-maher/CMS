import { z } from 'zod'

const phoneValidation = z.string().regex(/^[0-9]{11}$/, 'Phone number must contain exactly 11 digits.')

export const phoneOnlyDigits = (value: string): string => {
  return value.replace(/[^0-9]/g, '')
}

export const loginSchema = z.object({
  email: z.string().email('Invalid email address'),
  password: z.string().min(1, 'Password is required'),
})

export const registerSchema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters'),
  email: z.string().email('Invalid email address'),
  password: z
    .string()
    .min(8, 'Password must be at least 8 characters')
    .regex(/[a-z]/, 'Password must contain a lowercase letter')
    .regex(/[A-Z]/, 'Password must contain an uppercase letter')
    .regex(/[0-9]/, 'Password must contain a number')
    .regex(/[@$!%*?&#^()_\-+=]/, 'Password must contain a special character'),
  password_confirmation: z.string(),
  invite_token: z.string().length(64, 'Invalid invite token'),
  class_id: z.number().int().positive().optional(),
  phone: phoneValidation.optional().or(z.literal('')),
}).refine((data) => data.password === data.password_confirmation, {
  message: 'Passwords do not match',
  path: ['password_confirmation'],
})

export const createUserSchema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters'),
  email: z.string().email('Invalid email address'),
  password: z
    .string()
    .min(8, 'Password must be at least 8 characters')
    .regex(/[a-z]/, 'Password must contain a lowercase letter')
    .regex(/[A-Z]/, 'Password must contain an uppercase letter')
    .regex(/[0-9]/, 'Password must contain a number')
    .regex(/[@$!%*?&#^()_\-+=]/, 'Password must contain a special character')
    .optional(),
  role: z.enum(['admin', 'servant', 'member']),
  class_id: z.number().int().positive().nullable().optional(),
  phone: phoneValidation.nullable().optional().or(z.literal('')),
  is_active: z.boolean().optional(),
})

export const createQRInviteSchema = z.object({
  type: z.enum(['servant_invite', 'member_invite']),
})

export const recordAttendanceSchema = z.object({
  qr_token: z.string().min(1, 'QR token is required'),
})

export type FormErrors<T> = Partial<Record<keyof T, string>>
