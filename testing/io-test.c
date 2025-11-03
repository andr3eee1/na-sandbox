#include <stdio.h>

int main() {
    FILE *in = fopen("/input", "r");
    if (in == NULL) {
        return 1;
    }

    FILE *out = fopen("/output", "w");
    if (out == NULL) {
        return 1;
    }

    char line[256];
    while (fgets(line, sizeof(line), in)) {
        fputs(line, out);
    }

    fclose(in);
    fclose(out);

    return 0;
}
