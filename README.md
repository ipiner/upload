# Upload

Pin 文件上传。

## 上传验证与存储

```php
use Pin\Upload\Rules\Upload;
use Pin\Upload\UploadedFile;

$data = $request->validate([
    'file' => ['required', new Upload()->disk('public')->extensions('jpg,jpeg,png')->max('5M')],
]);

$file = UploadedFile::item($data['file']);
$path = $file->storeAs('avatars'); // 默认使用 UUID 文件名

if ($path !== false) {
    $url = $file->url();
}
```

- `min()`、`max()` 支持字节数或 `1K`、`5M` 等大小字符串，包含边界值；`0` 表示不限制，负数会抛出 `InvalidArgumentException`。
- `extensions()` 接受数组或逗号分隔的字符串，自动去除空白、统一小写并去重。验证依据文件内容探测出的 MIME 类型及其对应扩展名，支持 `jpg` / `jpeg` 等别名。
- 同一个规则实例可以验证多个文件。默认错误格式为 `code|message`，`new Upload(false)` 仅返回消息。
- `UploadedFile::items()` 包含本次请求中经过内容验证的文件及其错误，可用于上传日志；非文件值和 PHP 上传失败的文件不会加入集合。
- `storeAs($path, $name, $options)` 支持磁盘名称或选项数组。成功后同步 `disk`、`path`、`name`；返回 `false` 时保留原信息，磁盘配置的异常仍会向上传递。
- 本地存储后，`file` 和 `pathname` 指向新副本；远程存储保留本地源文件。`move()` 仅用于本地磁盘，移动后仍可调用 `storeAs()`。

## 图片处理

```php
$file->thumb(replace: true, width: 800); // 等比例缩小，不放大
$file->thumb(width: 's');               // 使用 pin.upload.thumb.s 配置
$file->thumb(width: 200, height: 100);
$file->water('/absolute/path/watermark.png');
```

缩略图尺寸必须为正整数，宽高至少指定一个；未知预设或无效尺寸会抛出 `InvalidArgumentException`。默认预设为 `s`、`m`、`l`。

独立缩略图信息保存在 `thumb` 属性中：预设使用预设名作为键；仅宽度使用宽度值；同时指定宽高使用 `200x100`，仅高度使用 `x100`。宽高之间新增 `x` 分隔，避免不同尺寸组合产生相同文件名。

覆盖图片后会更新大小和尺寸，多次处理仍保留首次处理前的 `original` 信息。缩略图和水印操作使用本地路径；上传到远程磁盘时，应先完成图片处理，再调用 `storeAs()`，独立生成的缩略图和水印文件需另行上传。

默认使用 GD，可通过继承 `UploadedFile` 并重写 `imageManager()` 切换图像驱动，再将子类绑定到容器中的 `UploadedFile::class`。

## Base64 文件

```php
use Pin\Upload\Rules\Base64File;

$request->validate([
    'avatar' => ['required', new Base64File(['image/png', 'image/jpeg'])],
]);

$file = $request->attributes->get('base64file.avatar');
$file->move(storage_path('app/avatars'), 'avatar.'.$file->guessExtension());
```

输入格式为 `data:image/png;base64,...`，支持额外 Data URI 参数。内容会严格解码，MIME 类型依据解码后的文件检测；无效内容或不允许的类型不会保留在请求属性中。临时文件在对象释放时自动清理，已移动的文件会保留。

## 文档

[https://ipiner.cn/packages/upload](https://ipiner.cn/packages/upload)
