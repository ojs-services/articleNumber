# Changelog

All notable changes to the **Article Number Plugin for OJS** (OJS 3.4 and 3.5 package) are documented here.

## [2.0.0.11] — 2026-11-27 — ojs3.4-3.5

First release for OJS 3.4.0.x and 3.5.0.x. One package for both.

- Article Number field on the publication Issue form, kept separate from *Pages*. The same number cannot be saved on two articles; a published article's number is locked.
- Shown on the article page and in the issue contents. On sites that use Atlas, Nivo, Axis or JournalPlus (or a theme that declares it) the article-page block starts off, because the theme already shows the number; anyone who wants it can turn it on, and a choice made in the settings window is kept. A theme that answers for itself (`rendersArticleNumber()`) is believed, yes or no, before the built-in list.
- Google Scholar: the article number as `citation_firstpage`, no `citation_lastpage`.
- Crossref `item_number`, JATS `elocation-id`, and the number in the citation.
- Copy article numbers typed into *Pages* to the new field (settings window or command line), with undo. `pages` is never changed; Roman-numeral ranges are treated as real page ranges. The Scan lists every value that needs a manual check in full at the top, shows articles and publication versions as separate counts, and the full result can be downloaded as CSV. Only published and scheduled articles are converted; articles in progress or declined are skipped and reported as such. When an article is published or scheduled with no Article Number and its Pages value follows the same rule as the Scan, the number is filled in automatically (one setting, on by default; a number typed by hand is never replaced).
- A number that another article already uses is never written, at publication or by the migration (within the uniqueness setting): a warning shows in the publish window and under the field, and the Scan lists the article for a manual check with the other submission's number.
- English, Turkish, Spanish, Portuguese (Portugal, Brazil). In other interface languages the plugin's texts appear in English.
- If this package is installed on OJS 3.3 by mistake, it stays inactive and the plugin list says which package to install; the site keeps working.
- Known limits in the README.

### Türkçe
OJS 3.4.0.x ve 3.5.0.x için ilk sürüm; tek paket ikisinde de çalışır.

- Yayın Sayı sekmesinde Makale Numarası alanı, *Sayfalar*'dan ayrı. Aynı numara iki makalede kaydedilemez; yayımlanmış makalenin numarası kilitlidir.
- Makale sayfasında ve sayı içindekilerde gösterilir. Atlas, Nivo, Axis ya da JournalPlus (veya bunu bildiren bir tema) kullanan sitelerde makale sayfasındaki blok kapalı başlar, çünkü tema numarayı zaten gösterir; isteyen açabilir, ayar penceresinde yapılan seçim korunur. Yanıtını kendisi veren tema (`rendersArticleNumber()`) evet de hayır da dese, yerleşik listeden önce ona uyulur.
- Google Scholar: makale numarası `citation_firstpage` olarak gider, `citation_lastpage` gönderilmez.
- Crossref `item_number`, JATS `elocation-id` ve atıfta numara.
- *Sayfalar*'a yazılmış makale numaralarını yeni alana kopyalama (ayar penceresi ya da komut satırı), geri alma ile. `pages` hiç değişmez; Roma rakamlı aralıklar gerçek sayfa aralığı sayılır. Tarama, elle kontrol gerektiren her değeri en üstte tam liste olarak gösterir; makale ve yayın sürümü sayıları ayrı verilir; tam sonuç CSV olarak indirilebilir. Yalnız yayımlanmış ve zamanlanmış makaleler dönüştürülür; devam eden ya da reddedilenler atlanır ve öyle bildirilir. Makale Numarası olmadan yayımlanan ya da zamanlanan bir makalenin Sayfalar değeri Tarama ile aynı kurala uyuyorsa numara kendiliğinden yazılır (tek ayar, varsayılan açık; elle girilmiş numara hiçbir zaman ezilmez).
- Başka bir makalenin kullandığı numara, yayımda da göçte de hiçbir zaman yazılmaz (benzersizlik ayarına göre): yayım penceresinde ve alanın altında uyarı çıkar, Tarama makaleyi öbür gönderinin numarasıyla elle kontrol listesine alır.
- İngilizce, Türkçe, İspanyolca, Portekizce (Portekiz, Brezilya). Başka arayüz dillerinde eklenti metinleri İngilizce görünür.
- Bu paket yanlışlıkla OJS 3.3'e kurulursa etkisiz kalır ve eklenti listesi hangi paketin kurulacağını söyler; site çalışmaya devam eder.
- Bilinen sınırlar README'de.
