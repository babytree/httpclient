package main

import (
	"fmt"
	"net/http"
	"time"
)

func main() {
	http.HandleFunc("/sleep", func(w http.ResponseWriter, _ *http.Request) {
		time.Sleep(time.Second)
		w.Write([]byte("返回值"))
	})

	http.HandleFunc("/upload", func(w http.ResponseWriter, r *http.Request) {
		var maxMemory int64 = 5 * 1024 * 1024
		r.ParseMultipartForm(maxMemory)
		var size int64 = 0
		if fileHeaders := r.MultipartForm.File["file"]; len(fileHeaders) > 0 {
			size = fileHeaders[0].Size
		}
		//返回上传文件大小，单位：字节
		w.Write([]byte(fmt.Sprintf("%d", size)))
	})
	fmt.Println(http.ListenAndServe("127.0.0.1:18888", nil))
}
