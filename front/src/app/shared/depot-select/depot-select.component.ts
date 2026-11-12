import { Component, Input, computed, forwardRef, inject, signal } from '@angular/core';
import { ControlValueAccessor, NG_VALUE_ACCESSOR } from '@angular/forms';
import { AuthService } from '../../core/services/auth.service';
import { Depot, DepotService } from '../../core/services/depot.service';

/**
 * "Dépôt" picker: a list of the depots from Paramètres › Dépôts, plus
 * "+ Nouveau dépôt…" to create one on the spot (with the `create stock`
 * permission). Works with [(ngModel)] / [ngModel]; the value is the depot
 * name (stock lots store the name), or '' for none.
 *
 * A value missing from the list (old lot, list not loaded) stays selectable,
 * and if the list cannot be loaded the picker falls back to a text field.
 */
@Component({
  selector: 'app-depot-select',
  standalone: true,
  templateUrl: './depot-select.component.html',
  styleUrl: './depot-select.component.scss',
  providers: [{ provide: NG_VALUE_ACCESSOR, useExisting: forwardRef(() => DepotSelectComponent), multi: true }],
})
export class DepotSelectComponent implements ControlValueAccessor {
  /** Id of the inner control, for a <label for="…">. */
  @Input() inputId: string | null = null;
  /** Extra class on the inner control (e.g. the compact inventory inputs). */
  @Input() controlClass = '';
  @Input() placeholder = '-- Choisir un dépôt --';

  private depotService = inject(DepotService);
  private auth = inject(AuthService);

  readonly depots = signal<Depot[]>([]);
  readonly loadFailed = signal(false);
  readonly value = signal('');
  readonly disabled = signal(false);
  readonly creating = signal(false);
  readonly newName = signal('');
  readonly saving = signal(false);
  readonly error = signal('');

  /** Names offered: the list, plus the current value when it is not in it. */
  readonly options = computed(() => {
    const names = this.depots().map((d) => d.name);
    const current = this.value();
    return current && !names.some((n) => n.toLowerCase() === current.toLowerCase()) ? [current, ...names] : names;
  });

  get canCreate(): boolean {
    return this.auth.hasPermission('create stock');
  }

  private onChange: (value: string) => void = () => {};
  private onTouched: () => void = () => {};

  constructor() {
    this.depotService.getDepots().subscribe({
      next: (list) => this.depots.set(list),
      error: () => this.loadFailed.set(true),
    });
  }

  writeValue(value: string | null | undefined): void {
    this.value.set((value ?? '').toString());
  }

  registerOnChange(fn: (value: string) => void): void {
    this.onChange = fn;
  }

  registerOnTouched(fn: () => void): void {
    this.onTouched = fn;
  }

  setDisabledState(isDisabled: boolean): void {
    this.disabled.set(isDisabled);
  }

  onSelect(selected: string): void {
    if (selected === '__new__') {
      this.creating.set(true);
      this.newName.set('');
      this.error.set('');
      return;
    }
    this.emit(selected);
  }

  onTyped(text: string): void {
    this.emit(text);
  }

  cancelCreate(): void {
    this.creating.set(false);
    this.error.set('');
  }

  confirmCreate(): void {
    const name = this.newName().trim();
    if (!name || this.saving()) return;

    const existing = this.depots().find((d) => d.name.toLowerCase() === name.toLowerCase());
    if (existing) {
      this.emit(existing.name);
      this.creating.set(false);
      return;
    }

    this.saving.set(true);
    this.error.set('');
    this.depotService.createDepot(name).subscribe({
      next: (depot) => {
        this.depots.update((list) => [...list, depot].sort((a, b) => a.name.localeCompare(b.name)));
        this.emit(depot.name);
        this.creating.set(false);
        this.saving.set(false);
      },
      error: (err) => {
        const errors = err?.error?.errors;
        const first = errors ? (Object.values(errors)[0] as string[] | undefined)?.[0] : null;
        this.error.set(first || err?.error?.message || 'Création impossible.');
        this.saving.set(false);
      },
    });
  }

  private emit(value: string): void {
    this.value.set(value);
    this.onChange(value);
    this.onTouched();
  }
}
