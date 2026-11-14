import { Component, EventEmitter, OnInit, Output, inject, signal } from '@angular/core';
import { DatePipe, DecimalPipe } from '@angular/common';
import { WebstoreService } from '../../data-access/webstore.service';
import { WebstoreBatchSummary } from '../../models/webstore.model';

/** Boutique en ligne — history of the batches of proposals (who computed, what was decided / published). */
@Component({
  selector: 'app-webstore-history',
  standalone: true,
  imports: [DatePipe, DecimalPipe],
  templateUrl: './webstore-history.component.html',
  styleUrls: ['./webstore-history.component.scss'],
})
export class WebstoreHistoryComponent implements OnInit {
  private service = inject(WebstoreService);

  /** A batch to open (read only when it was replaced). */
  @Output() open = new EventEmitter<number>();

  readonly rows = signal<WebstoreBatchSummary[]>([]);
  readonly loading = signal(false);
  readonly error = signal('');
  readonly page = signal(1);
  readonly lastPage = signal(1);

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.error.set('');
    this.service.batches(this.page()).subscribe({
      next: (res) => {
        this.rows.set(res.data);
        this.lastPage.set(res.meta.last_page);
        this.loading.set(false);
      },
      error: () => { this.loading.set(false); this.error.set('Historique indisponible.'); },
    });
  }

  goToPage(page: number): void {
    this.page.set(page);
    this.load();
  }

  count(row: WebstoreBatchSummary, status: 'proposed' | 'approved' | 'rejected' | 'published' | 'failed' | 'superseded'): number {
    return row.statuses[status] ?? 0;
  }
}
