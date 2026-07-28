import { AbstractControl, ValidationErrors, ValidatorFn, Validators } from '@angular/forms';

export const securePasswordValidators = [
  Validators.required,
  Validators.minLength(12),
  Validators.pattern(/[a-z]/),
  Validators.pattern(/[A-Z]/),
  Validators.pattern(/[0-9]/),
  Validators.pattern(/[^A-Za-z0-9]/),
];

export const passwordsMatchValidator: ValidatorFn = (
  control: AbstractControl,
): ValidationErrors | null => {
  const password = control.get('password')?.value as string | undefined;
  const confirmation = control.get('password_confirmation')?.value as string | undefined;

  return password === confirmation ? null : { passwordMismatch: true };
};
