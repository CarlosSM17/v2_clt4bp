#include <stdio.h>

int main(void) {
    int n;
    double x, suma = 0.0;
    scanf("%d", &n);
    for (int i = 0; i < n; i++) {
        scanf("%lf", &x);
        suma += x;
    }
    printf("%.2f\n", suma / n);
    return 0;
}
