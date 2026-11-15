import { Component, EventEmitter, Input, OnInit, Output } from '@angular/core';
import { CommonModule, DecimalPipe } from '@angular/common';
import { ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';
import { Account, TransferPayload } from '../../../core/models/account.model';

@Component({
  selector: 'app-transfer-form',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, DecimalPipe],
  templateUrl: './transfer-form.component.html',
  styleUrls: ['./transfer-form.component.scss']
})
export class TransferFormComponent implements OnInit {
  @Input() accounts: Account[] = [];
  @Output() submitted = new EventEmitter<TransferPayload>();
  @Output() cancelled = new EventEmitter<void>();

  form!: FormGroup;

  constructor(private fb: FormBuilder) {}

  ngOnInit(): void {
    this.form = this.fb.group({
      source_account_id: ['', [Validators.required]],
      destination_account_id: ['', [Validators.required]],
      amount: [null, [Validators.required, Validators.min(0.01)]],
      date: [this.todayDate(), [Validators.required]],
      description: ['']
    });
  }

  private todayDate(): string {
    return new Date().toISOString().slice(0, 10);
  }

  get activeAccounts() {
    return this.accounts.filter(a => a.is_active);
  }

  private find(id: unknown): Account | null {
    return id ? this.accounts.find((a) => String(a.id) === String(id)) ?? null : null;
  }

  get sourceAccount(): Account | null {
    return this.find(this.form.get('source_account_id')?.value);
  }

  get destinationAccount(): Account | null {
    return this.find(this.form.get('destination_account_id')?.value);
  }

  /** Same account on both sides: shown under the fields, submit disabled. */
  get sameAccount(): boolean {
    const src = this.form.get('source_account_id')?.value;
    return !!src && String(src) === String(this.form.get('destination_account_id')?.value);
  }

  /** More than the source balance: only a warning (a balance can go negative). */
  get exceedsBalance(): boolean {
    const amount = Number(this.form.get('amount')?.value);
    const src = this.sourceAccount;
    return !!src && amount > 0 && amount > Number(src.current_balance);
  }

  swap(): void {
    const src = this.form.get('source_account_id')?.value;
    this.form.patchValue({ source_account_id: this.form.get('destination_account_id')?.value, destination_account_id: src });
  }

  onSubmit(): void {
    if (this.form.invalid || this.sameAccount) {
      this.form.markAllAsTouched();
      return;
    }

    const payload: TransferPayload = this.form.value;
    this.submitted.emit(payload);
  }

  onCancel(): void {
    this.cancelled.emit();
  }
}
