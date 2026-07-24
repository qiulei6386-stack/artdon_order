# Artdon Procurement Platform V1.0 — 页面路由清单

本项目采用数据驱动路由，不需要为 70–100 个页面复制 70–100 套 PHP。当前栏目路由和产品详情页均由共享模板生成。

## 页面数量

- 栏目与内容路由：126
- 示例产品详情页：12
- 独立产品配置页：12
- 独立页面：首页、Contact、Cart、Sitemap
- 当前可访问页面合计：154

## Ready Stock

| 页面 | 路由 | 模板 |
|---|---|---|
| Ready Stock 总页 | `/ready-stock` | `listing` |
| All Ready Stock | `/ready-stock/all-ready-stock` | `listing` |
| Track Lighting | `/ready-stock/track-lighting` | `listing` |
| Downlights | `/ready-stock/downlights` | `listing` |
| Magnetic System | `/ready-stock/magnetic-system` | `listing` |
| Linear Lighting | `/ready-stock/linear-lighting` | `listing` |
| Accessories | `/ready-stock/accessories` | `listing` |
| New Arrival | `/ready-stock/new-arrival` | `listing` |
| Clearance | `/ready-stock/clearance` | `listing` |

## Products

| 页面 | 路由 | 模板 |
|---|---|---|
| Products 总页 | `/products` | `listing` |
| Track Lighting | `/products/track-lighting` | `listing` |
| ↳ Track Spot | `/products/track-lighting/track-spot` | `listing` |
| ↳ Track Linear | `/products/track-lighting/track-linear` | `listing` |
| ↳ Wall Washer | `/products/track-lighting/wall-washer` | `listing` |
| ↳ Zoom | `/products/track-lighting/zoom` | `listing` |
| ↳ Pendant | `/products/track-lighting/pendant` | `listing` |
| ↳ Accessories | `/products/track-lighting/accessories` | `listing` |
| Recessed Downlights | `/products/recessed-downlights` | `listing` |
| ↳ Fixed | `/products/recessed-downlights/fixed` | `listing` |
| ↳ Adjustable | `/products/recessed-downlights/adjustable` | `listing` |
| ↳ Trimless | `/products/recessed-downlights/trimless` | `listing` |
| ↳ IP65 | `/products/recessed-downlights/ip65` | `listing` |
| ↳ High Ceiling | `/products/recessed-downlights/high-ceiling` | `listing` |
| ↳ Mini | `/products/recessed-downlights/mini` | `listing` |
| Magnetic | `/products/magnetic` | `listing` |
| Linear | `/products/linear` | `listing` |
| Surface | `/products/surface` | `listing` |
| Pendant | `/products/pendant` | `listing` |
| Outdoor | `/products/outdoor` | `listing` |
| Driver | `/products/driver` | `listing` |
| Track Rail | `/products/track-rail` | `listing` |
| Strip | `/products/strip` | `listing` |
| Sensor | `/products/sensor` | `listing` |
| Emergency | `/products/emergency` | `listing` |
| Accessories | `/products/accessories` | `listing` |
| Spare Parts | `/products/spare-parts` | `listing` |
| All Products | `/products/all-products` | `listing` |

## Solutions

| 页面 | 路由 | 模板 |
|---|---|---|
| Solutions 总页 | `/solutions` | `solution` |
| Retail | `/solutions/retail` | `solution` |
| Hospitality | `/solutions/hospitality` | `solution` |
| Office | `/solutions/office` | `solution` |
| Residential | `/solutions/residential` | `solution` |
| Museum | `/solutions/museum` | `solution` |
| Gallery | `/solutions/gallery` | `solution` |
| Supermarket | `/solutions/supermarket` | `solution` |
| Shopping Mall | `/solutions/shopping-mall` | `solution` |
| Restaurant | `/solutions/restaurant` | `solution` |
| Airport | `/solutions/airport` | `solution` |
| Education | `/solutions/education` | `solution` |
| Healthcare | `/solutions/healthcare` | `solution` |

## Projects

| 页面 | 路由 | 模板 |
|---|---|---|
| Projects 总页 | `/projects` | `project` |
| Retail | `/projects/retail` | `project` |
| Hospitality | `/projects/hospitality` | `project` |
| Office | `/projects/office` | `project` |
| Museum | `/projects/museum` | `project` |
| Residential | `/projects/residential` | `project` |
| Commercial | `/projects/commercial` | `project` |
| Airport | `/projects/airport` | `project` |
| All Projects | `/projects/all-projects` | `project` |

## Resources

| 页面 | 路由 | 模板 |
|---|---|---|
| Resources 总页 | `/resources` | `resource` |
| Catalogue | `/resources/catalogue` | `resource` |
| Series Brochure | `/resources/series-brochure` | `resource` |
| Datasheet | `/resources/datasheet` | `resource` |
| IES | `/resources/ies` | `resource` |
| BIM | `/resources/bim` | `resource` |
| CAD | `/resources/cad` | `resource` |
| Installation | `/resources/installation` | `resource` |
| Certificates | `/resources/certificates` | `resource` |
| Warranty | `/resources/warranty` | `resource` |
| Videos | `/resources/videos` | `resource` |
| Downloads | `/resources/downloads` | `resource` |
| FAQ | `/resources/faq` | `resource` |

## Learn

| 页面 | 路由 | 模板 |
|---|---|---|
| Learn 总页 | `/learn` | `learn` |
| Lighting Basics | `/learn/lighting-basics` | `learn` |
| Product Guide | `/learn/product-guide` | `learn` |
| Installation | `/learn/installation` | `learn` |
| Design Ideas | `/learn/design-ideas` | `learn` |
| Buying Guide | `/learn/buying-guide` | `learn` |
| Industry News | `/learn/industry-news` | `learn` |
| Company News | `/learn/company-news` | `learn` |
| Videos | `/learn/videos` | `learn` |

## AI Assistant

| 页面 | 路由 | 模板 |
|---|---|---|
| AI Assistant 总页 | `/ai` | `ai` |
| Product Finder | `/ai/product-finder` | `ai` |
| Lighting Calculator | `/ai/lighting-calculator` | `ai` |
| Beam Angle Selector | `/ai/beam-angle-selector` | `ai` |
| Driver Selector | `/ai/driver-selector` | `ai` |
| Compare Products | `/ai/compare-products` | `ai` |
| AI Consultant | `/ai/ai-consultant` | `ai` |

## Procurement

| 页面 | 路由 | 模板 |
|---|---|---|
| Procurement 总页 | `/procurement` | `procurement` |
| Quick RFQ | `/procurement/quick-rfq` | `procurement` |
| Sample Order | `/procurement/sample-order` | `procurement` |
| Ready Stock | `/procurement/ready-stock` | `procurement` |
| OEM | `/procurement/oem` | `procurement` |
| ODM | `/procurement/odm` | `procurement` |
| Bulk Order | `/procurement/bulk-order` | `procurement` |
| Project Package | `/procurement/project-package` | `procurement` |
| Procurement Service | `/procurement/procurement-service` | `procurement` |

## Support

| 页面 | 路由 | 模板 |
|---|---|---|
| Support 总页 | `/support` | `support` |
| Shipping | `/support/shipping` | `support` |
| Payment | `/support/payment` | `support` |
| Lead Time | `/support/lead-time` | `support` |
| Warranty | `/support/warranty` | `support` |
| Returns | `/support/returns` | `support` |
| Quality | `/support/quality` | `support` |
| FAQ | `/support/faq` | `support` |
| Contact | `/support/contact` | `support` |
| Downloads | `/support/downloads` | `support` |
| Technical Support | `/support/technical-support` | `support` |

## About

| 页面 | 路由 | 模板 |
|---|---|---|
| About 总页 | `/about` | `about` |
| Company | `/about/company` | `about` |
| Factory | `/about/factory` | `about` |
| Team | `/about/team` | `about` |
| Manufacturing | `/about/manufacturing` | `about` |
| Quality | `/about/quality` | `about` |
| Exhibition | `/about/exhibition` | `about` |
| Careers | `/about/careers` | `about` |
| Contact | `/about/contact` | `about` |

## My Account

| 页面 | 路由 | 模板 |
|---|---|---|
| My Account 总页 | `/account` | `account` |
| Dashboard | `/account/dashboard` | `account` |
| Orders | `/account/orders` | `account` |
| Quotes | `/account/quotes` | `account` |
| Wishlist | `/account/wishlist` | `account` |
| Compare | `/account/compare` | `account` |
| Downloads | `/account/downloads` | `account` |
| Address | `/account/address` | `account` |
| Settings | `/account/settings` | `account` |

## 产品详情示例

| 型号 | 产品 | 路由 |
|---|---|---|
| AL1010 | Recessed Downlight | `/product/AL1010` |
| AL1011 | Adjustable Downlight | `/product/AL1011` |
| AT2020 | Track Spotlight | `/product/AT2020` |
| ATL2030 | Track Linear | `/product/ATL2030` |
| MW3010 | Magnetic Wall Washer | `/product/MW3010` |
| LN4010 | Architectural Linear Light | `/product/LN4010` |
| PD5010 | Decorative Pendant | `/product/PD5010` |
| OD6010 | Outdoor Wall Light | `/product/OD6010` |
| DR7010 | DALI-2 LED Driver | `/product/DR7010` |
| TR8010 | 3-Circuit Track Rail | `/product/TR8010` |
| AC9010 | Honeycomb Louvre | `/product/AC9010` |
| SP9020 | Replacement Reflector | `/product/SP9020` |

## 产品配置页示例

产品详情页用于 SEO、规格、应用和资料展示；配置页标记为 `noindex,follow`，用于选项组合与下单。

| 型号 | 配置路由 |
|---|---|
| AL1010 | `/configure/AL1010` |
| AL1011 | `/configure/AL1011` |
| AT2020 | `/configure/AT2020` |
| ATL2030 | `/configure/ATL2030` |
| MW3010 | `/configure/MW3010` |
| LN4010 | `/configure/LN4010` |
| PD5010 | `/configure/PD5010` |
| OD6010 | `/configure/OD6010` |
| DR7010 | `/configure/DR7010` |
| TR8010 | `/configure/TR8010` |
| AC9010 | `/configure/AC9010` |
| SP9020 | `/configure/SP9020` |
